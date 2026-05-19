# =============================================================================
# Resonance — Makefile
# =============================================================================
# Cibles disponibles en Phase 1 (infra) : up, down, restart, logs, ps, clean,
# env, status. Les cibles applicatives (seed, dump, restore, benchmark, test...)
# sont déclarées ici comme stubs et seront implémentées dans les phases
# suivantes (cf. resonance-spec.md §11).
# =============================================================================

SHELL := /bin/bash
COMPOSE := docker compose

# Charge les variables d'environnement de .env si présent (gitignored).
# Permet aux cibles de lire NGINX_PORT, FRONTEND_PORT, etc. sans devoir
# les ré-exporter manuellement à chaque commande. `-include` reste
# silencieux si le fichier n'existe pas.
-include .env
export

# Valeurs par défaut alignées sur .env.example (au cas où .env est absent).
NGINX_PORT ?= 8080
FRONTEND_PORT ?= 3000

# URL host du frontal Resonance, dérivée de NGINX_PORT. Utilisée par les
# cibles de mesure (`make lighthouse`). Override possible :
#   RESONANCE_BASE_URL=http://other:port make lighthouse
# Note : `make k6` injecte sa propre URL `http://nginx` (DNS interne du
# réseau Compose), insensible au port host.
RESONANCE_BASE_URL ?= http://localhost:$(NGINX_PORT)

.DEFAULT_GOAL := help

# ----- Aide ------------------------------------------------------------------

.PHONY: help
help: ## Affiche cette aide
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage: make \033[36m<cible>\033[0m\n\nCibles :\n"} /^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

# ----- Pré-requis ------------------------------------------------------------

.PHONY: env
env: ## Crée .env depuis .env.example si absent
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		echo ".env créé depuis .env.example"; \
	else \
		echo ".env déjà présent — aucune modification"; \
	fi

# ----- Cycle de vie de la stack ----------------------------------------------

.PHONY: up
up: env ensure-images ensure-backend-deps ## Démarre toute la stack en arrière-plan
	$(COMPOSE) up -d
	@echo ""
	@echo "Stack démarrée. Services :"
	@echo "  - PostgreSQL  → localhost:$${POSTGRES_PORT:-5432}"
	@echo "  - Redis       → localhost:$${REDIS_PORT:-6379}"
	@echo "  - MinIO API   → http://localhost:$${MINIO_API_PORT:-9000}"
	@echo "  - MinIO UI    → http://localhost:$${MINIO_CONSOLE_PORT:-9001}"
	@echo "  - Mailpit UI  → http://localhost:$${MAILPIT_UI_PORT:-8025}"

# Build initial des images locales (backend/frontend) si absentes du cache
# Docker. Sans ce garde-fou, `docker compose up` tente de PULL les images
# `resonance/backend:dev` et `resonance/frontend:starter` depuis Docker Hub
# (où elles n'existent pas) → "pull access denied". No-op silencieuse quand
# les images sont déjà présentes (cas nominal après le premier `make up`).
.PHONY: ensure-images
ensure-images:
	@if ! docker image inspect resonance/backend:dev >/dev/null 2>&1; then \
		echo "→ Image resonance/backend:dev absente, build initial (1-2 min)..."; \
		$(COMPOSE) build backend; \
	fi
	@if ! docker image inspect resonance/frontend:starter >/dev/null 2>&1; then \
		echo "→ Image resonance/frontend:starter absente, build initial (1-2 min)..."; \
		$(COMPOSE) build frontend; \
	fi

# Installe les artefacts backend gitignorés absents d'un clone frais :
#   - backend/.env (sans lui, Laravel boot avec une config par défaut
#     incohérente avec la stack Docker → erreurs DB/queue/cache).
#   - backend/vendor (sans lui, toute commande artisan tombe sur
#     "Failed opening required '/app/vendor/autoload.php'"). Le bind-mount
#     ./backend:/app masque tout vendor pré-installé dans l'image, donc
#     composer install doit tourner dans un container monté.
#   - APP_KEY dans backend/.env (sans lui, Laravel jette "No application
#     encryption key has been specified.").
# `run --rm --no-deps` : container éphémère, ne démarre pas postgres/redis
# pour ce setup (composer + key:generate sont autosuffisants). On force le
# user à l'UID/GID de l'hôte (et non `app` bakée à 1000 dans l'image) pour
# que les fichiers créés dans le bind-mount appartiennent à l'utilisateur
# hôte — sans ça, `mkdir vendor` échoue sur tout host dont l'UID ≠ 1000
# ("/app/vendor does not exist and could not be created"). COMPOSER_HOME
# est redirigé vers /tmp car /home/app/.composer est owned par UID 1000.
# Idempotent : chaque bloc est conditionnel et silencieux si l'artefact
# est déjà là.
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

.PHONY: ensure-backend-deps
ensure-backend-deps:
	@if [ ! -f backend/.env ]; then \
		echo "→ backend/.env absent, copie depuis backend/.env.example..."; \
		cp backend/.env.example backend/.env; \
	fi
	@if [ ! -f backend/vendor/autoload.php ]; then \
		echo "→ backend/vendor absent, composer install (1-2 min)..."; \
		$(COMPOSE) run --rm --no-deps \
		  -u "$(HOST_UID):$(HOST_GID)" \
		  -e COMPOSER_HOME=/tmp/composer \
		  backend composer install --no-interaction --prefer-dist --no-progress; \
	fi
	@if ! grep -qE "^APP_KEY=base64:" backend/.env; then \
		echo "→ APP_KEY absent dans backend/.env, génération..."; \
		$(COMPOSE) run --rm --no-deps \
		  -u "$(HOST_UID):$(HOST_GID)" \
		  backend php artisan key:generate --force; \
	fi

.PHONY: down
down: ## Arrête la stack (volumes conservés)
	$(COMPOSE) down

.PHONY: restart
restart: ## Redémarre la stack
	$(COMPOSE) restart

.PHONY: ps
ps: ## Statut des conteneurs
	$(COMPOSE) ps

.PHONY: status
status: ps ## Alias de `ps`

.PHONY: logs
logs: ## Suit les logs de tous les services (Ctrl-C pour quitter)
	$(COMPOSE) logs -f --tail=100

.PHONY: clean
clean: ## Arrête la stack ET supprime les volumes (perte de données locale)
	$(COMPOSE) down -v
	@echo "Volumes supprimés."

# ----- Backend (Laravel) -----------------------------------------------------

.PHONY: backend-build
backend-build: ## (Re)build l'image backend
	$(COMPOSE) build backend

# Toutes les commandes admin backend passent en `-u app` : depuis Phase 4-bis
# le master FPM tourne en root (pour drop privileges vers les workers) ; sans
# `-u app` les fichiers générés (vendor/, storage/, …) appartiendraient à
# root sur le bind-mount.

.PHONY: backend-shell
backend-shell: ## Shell interactif dans le container backend
	$(COMPOSE) exec -u app backend bash

.PHONY: artisan
artisan: ## Exécute artisan dans le container (usage: make artisan cmd="route:list")
	$(COMPOSE) exec -u app backend php artisan $(cmd)

.PHONY: composer
composer: ## Exécute composer dans le container (usage: make composer cmd="dump-autoload")
	$(COMPOSE) exec -u app backend composer $(cmd)

.PHONY: pest
pest: ## Lance la suite Pest
	$(COMPOSE) exec -u app backend ./vendor/bin/pest

.PHONY: db-create-test
db-create-test: ## Crée la base postgres dédiée aux tests (idempotent)
	$(COMPOSE) exec -T postgres sh -c 'psql -U resonance -d resonance -tc "SELECT 1 FROM pg_database WHERE datname = '"'"'resonance_test'"'"'" | grep -q 1 || createdb -U resonance resonance_test'

.PHONY: tinker
tinker: ## Ouvre une console tinker
	$(COMPOSE) exec -u app backend php artisan tinker

.PHONY: migrate
migrate: ## Lance les migrations (idempotent)
	$(COMPOSE) exec -u app backend php artisan migrate

.PHONY: migrate-fresh
migrate-fresh: ## Drop & migrate (base de données effacée !)
	$(COMPOSE) exec -u app backend php artisan migrate:fresh

# ----- Stubs (phases ultérieures) --------------------------------------------
# Ces cibles sont déclarées dès maintenant pour stabiliser le contrat. Elles
# seront implémentées dans les phases correspondantes (cf. spec §11).

.PHONY: seed-light
seed-light: ## Seed du dataset léger (50 events, 200 tickets)
	$(COMPOSE) exec -u app backend php artisan resonance:seed --dataset=light --fresh

.PHONY: seed-realistic
seed-realistic: ## Seed du dataset réaliste (200k tickets, < 10 min)
	$(COMPOSE) exec -u app backend php artisan resonance:seed --dataset=realistic --fresh

.PHONY: bench-seed
bench-seed: ## Bench du seeder réaliste (mesure de référence — cible < 10 min)
	@echo "Bench du seeder réaliste…"
	@time $(COMPOSE) exec -u app backend php artisan resonance:seed --dataset=realistic --fresh --no-interaction

.PHONY: dump
dump: ## Dump SQL gzippé → infra/seeds-dump/realistic.sql.gz (overwrite)
	$(COMPOSE) exec -u app backend php artisan resonance:dump-database

.PHONY: restore
restore: ## Restore depuis infra/seeds-dump/realistic.sql.gz + pool MinIO (cible < 30s)
	$(COMPOSE) exec -u app backend php artisan resonance:restore-database
	# Le dump SQL ne contient PAS les binaires MinIO (volume `minio_data`
	# séparé). Sans ce 2e step, les `media.path = seed-pool/img-…jpg` pointent
	# sur des objets manquants → 404 sur toutes les images de la stack →
	# mesures Lighthouse silencieusement faussées (LCP image perdu, total
	# byte weight sous-évalué). Idempotent : ~30 HEAD requests s'il n'y a
	# rien à restaurer (~ 100 ms).
	$(COMPOSE) exec -u app backend php artisan resonance:ensure-media-pool --quiet-when-complete

# ----- Frontend (Nuxt) -------------------------------------------------------
# Phase 4-bis : le service `frontend` est en mode prod-like (image multi-stage
# avec build au build-time). Pour modifier le code, on doit reconstruire
# l'image puis redémarrer le service → `make frontend-rebuild`.
#
# Le source du frontend n'est plus dans le container au runtime ; pour
# lint/typecheck on utilise un container Node éphémère (service
# `frontend-tools`, profile "tools").

.PHONY: frontend-build
frontend-build: ## (Re)build l'image frontend (multi-stage : npm ci + nuxt build)
	$(COMPOSE) build frontend

.PHONY: frontend-rebuild
frontend-rebuild: ## Rebuild l'image frontend ET redémarre le service
	$(COMPOSE) build frontend
	$(COMPOSE) up -d frontend

.PHONY: frontend-shell
frontend-shell: ## Shell interactif dans le container frontend (runtime ; .output uniquement)
	$(COMPOSE) exec frontend sh

.PHONY: frontend-tools-shell
frontend-tools-shell: ## Shell éphémère avec le source frontend monté (devDeps disponibles)
	$(COMPOSE) --profile tools run --rm frontend-tools sh

.PHONY: frontend-lint
frontend-lint: ## ESLint frontend (container Node éphémère, profile tools)
	$(COMPOSE) --profile tools run --rm frontend-tools sh -c "[ -d node_modules ] || npm ci --no-audit --no-fund; npm run lint"

.PHONY: frontend-typecheck
frontend-typecheck: ## Type-check vue-tsc (container Node éphémère, profile tools)
	$(COMPOSE) --profile tools run --rm frontend-tools sh -c "[ -d node_modules ] || npm ci --no-audit --no-fund; npm run typecheck"

# ----- Mesure de performance (Lighthouse CI + k6) ----------------------------
# Toute mesure repart d'un état canonique : `make lighthouse` et `make k6`
# exécutent automatiquement `make restore` avant les scénarios. Les
# scénarios k6 (notamment checkout-stress) consomment des stocks
# `ticket_categories.sold` persistés en base ; lancer une mesure deux
# fois sans reset produit des chiffres incomparables (le second run
# hérite d'un quota épuisé).
#
# Pour itérer rapidement sans reset (typiquement pendant le développement
# d'une branche solution), utiliser `lighthouse-no-restore` /
# `k6-no-restore`.

.PHONY: lighthouse-no-restore
lighthouse-no-restore: ## Audit Lighthouse CI SANS reset DB préalable (itération dev)
	@test -x ./node_modules/.bin/lhci || { echo "@lhci/cli absent. Lance d'abord :"; echo "  npm install"; exit 1; }
	@echo "→ RESONANCE_BASE_URL=$(RESONANCE_BASE_URL) (NGINX_PORT=$(NGINX_PORT))"; \
	echo "→ Résolution du star event slug..."; \
	STAR_SLUG=$$(./load-tests/scripts/resolve-star-slug.sh) || exit 1; \
	echo "  STAR_SLUG=$$STAR_SLUG"; \
	rm -rf docs/benchmarks/lighthouse-latest; \
	mkdir -p docs/benchmarks/lighthouse-latest; \
	echo "→ Lancement de lhci collect (3 runs × 2 URLs)..."; \
	RESONANCE_BASE_URL="$(RESONANCE_BASE_URL)" \
	  STAR_SLUG="$$STAR_SLUG" \
	  LHCI_OUTPUT_DIR="$$(pwd)/docs/benchmarks/lighthouse-latest" \
	  ./node_modules/.bin/lhci collect --config=load-tests/lighthouse/lighthouserc.cjs && \
	echo "→ Export des rapports vers docs/benchmarks/lighthouse-latest/..." && \
	RESONANCE_BASE_URL="$(RESONANCE_BASE_URL)" \
	  STAR_SLUG="$$STAR_SLUG" \
	  LHCI_OUTPUT_DIR="$$(pwd)/docs/benchmarks/lighthouse-latest" \
	  ./node_modules/.bin/lhci upload --config=load-tests/lighthouse/lighthouserc.cjs; \
	echo ""; \
	echo "Rapports HTML :"; \
	find docs/benchmarks/lighthouse-latest -name '*.html' | sort | sed 's|^|  |'

.PHONY: lighthouse
lighthouse: restore lighthouse-no-restore ## Audit Lighthouse CI (reset DB → mesure reproductible)

.PHONY: k6-no-restore
k6-no-restore: ## Lance les 3 scénarios k6 SANS reset DB préalable (itération dev)
	@echo "→ Résolution du star event slug..."; \
	STAR_SLUG=$$(./load-tests/scripts/resolve-star-slug.sh) || exit 1; \
	echo "  STAR_SLUG=$$STAR_SLUG"; \
	rm -rf docs/benchmarks/k6-latest; \
	mkdir -p docs/benchmarks/k6-latest; \
	NETWORK="$${COMPOSE_PROJECT_NAME:-resonance}_resonance"; \
	for scenario in homepage-load search-load checkout-stress; do \
	  echo ""; \
	  echo "──────────────────────────────────────────────────"; \
	  echo "→ k6 : $$scenario"; \
	  echo "──────────────────────────────────────────────────"; \
	  docker run --rm \
	    --user "$$(id -u):$$(id -g)" \
	    --network "$$NETWORK" \
	    -e RESONANCE_BASE_URL="http://nginx" \
	    -e STAR_SLUG="$$STAR_SLUG" \
	    -v "$$(pwd)/load-tests/k6:/scripts:ro" \
	    -v "$$(pwd)/docs/benchmarks/k6-latest:/out" \
	    grafana/k6:latest run \
	      --summary-export=/out/$$scenario-summary.json \
	      /scripts/$$scenario.js \
	    || { echo "Échec du scénario $$scenario"; exit 1; }; \
	done; \
	echo ""; \
	echo "Sommaires JSON :"; \
	find docs/benchmarks/k6-latest -name '*.json' | sort | sed 's|^|  |'

.PHONY: k6
k6: restore k6-no-restore ## k6 (reset DB → mesure reproductible)

.PHONY: benchmark
benchmark: restore lighthouse-no-restore k6-no-restore ## Audit complet (un seul reset DB, puis lighthouse + k6)

.PHONY: test
test: db-create-test ## Lance la suite de tests (Pest backend ; frontend en Phase 3)
	$(COMPOSE) exec -u app backend ./vendor/bin/pest
