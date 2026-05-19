# Resonance - fil rouge formation perf web

Plateforme de billetterie d'événements culturels (concerts, festivals, théâtre, conférences,
expositions) servant de cas d'étude à une formation **performance web sur 3 jours**.

Le repo livre deux états de la même application :

- **`main`** - `resonance-starter`, volontairement non-optimisé (N+1, pas de cache,
  images full-size, etc.) - point de départ des ateliers.
- **`final`** - `resonance-final`, état pleinement optimisé (eager loading, Redis, ISR,
  index Postgres, queues, Octane).

Le passage de l'un à l'autre se fait par étapes via les branches `solution/jX-name`,
chacune correspondant à un atelier.

## Stack

- **Frontend** : Nuxt 4 + TypeScript strict + Vue 3 Composition API + Pinia + Tailwind v3.
- **Backend** : Laravel 13.7 + PHP 8.3 (FPM) + Sanctum (token mode) + Eloquent.
- **Données** : PostgreSQL 16, Redis 7, MinIO (S3-compatible).
- **Infra** : Docker Compose, Nginx (frontal HTTP unique), Mailpit (SMTP local).
- **Mesure** : Lighthouse CI, k6, `nuxi analyze`.

> **Starter** : le starter tourne en mode **production-like
> NON optimisé** (build Nuxt prod + PHP-FPM + Nginx non tuné). Plus de hot
> reload, plus de `nuxt dev`. C'est un environnement de mesure, pas de dev.
> Voir `docs/architecture.md`.

## Prérequis

- Docker ≥ 24 + Docker Compose plugin v2.
- GNU Make.
- Pour mesurer : Node 20+ (Lighthouse CI s'exécute sur l'host).

## Démarrage rapide

```bash
cp .env.example .env
cp backend/.env.example backend/.env
make up
make seed-light          # migrate:fresh + seed (cf. "Données" plus bas)
```

Le premier `make up` builde les images backend (PHP-FPM) et frontend
(multi-stage Nuxt build prod), ce qui peut prendre 1-3 minutes. Les
fois suivantes, c'est instantané.

> **Note** : `make up` ne copie que `.env` à la racine (via la cible
> `make env`). Le `backend/.env` est gitignoré et doit être copié
> manuellement depuis `backend/.env.example`. Sans lui, Laravel
> démarre avec une config par défaut incohérente avec la stack Docker.

Une fois la stack démarrée, les services suivants sont accessibles :

| Service          | URL / port local                  | Notes                                                  |
|------------------|-----------------------------------|--------------------------------------------------------|
| **App Resonance**| **`http://localhost:${NGINX_PORT}`** (défaut 8080) | **point d'entrée principal - derrière Nginx**     |
| PostgreSQL       | `localhost:5432`                  | creds dans `.env`                                      |
| Redis            | `localhost:6379`                  |                                                        |
| MinIO API        | `http://localhost:9000`           | S3-compatible                                          |
| MinIO Console    | `http://localhost:9001`           | UI admin (creds dans `.env`)                           |
| Mailpit SMTP     | `localhost:1025`                  | à utiliser depuis l'app Laravel                        |
| Mailpit UI       | `http://localhost:8025`           | inbox web                                              |
| Frontend (debug) | `http://localhost:3000`           | accès direct Nuxt SSR (court-circuite Nginx, debug)    |

L'API Laravel n'est pas exposée en direct sur l'host : elle est servie
via FastCGI à travers Nginx (`http://localhost:${NGINX_PORT}/api/v1/...`,
défaut 8080). Si le port 8080 est déjà occupé sur votre machine (e.g.
keycloak ailleurs), modifiez `NGINX_PORT` dans `.env` (gitignored) et
les outils de mesure suivront automatiquement via `RESONANCE_BASE_URL`.

Le bucket MinIO `resonance` est créé automatiquement au démarrage.

## Commandes Make courantes

```bash
make up                # démarre la stack en arrière-plan
make ps                # statut des conteneurs
make logs              # suit les logs (ctrl-c pour quitter)
make down              # arrête la stack
make restart           # redémarre la stack
make clean             # arrête + supprime les volumes (perte de données locale)

# Frontend en mode prod-like
make frontend-rebuild  # rebuild image frontend (nuxt build) + redémarre le service
make frontend-lint     # ESLint sur le source frontend (container Node éphémère)
make frontend-typecheck # vue-tsc sur le source frontend (container Node éphémère)

# Backend (artisan, composer, pest)
make artisan cmd="route:list"
make composer cmd="dump-autoload"
make pest

# Mesure
make lighthouse        # 3 runs × 2 URLs (mobile / Slow 4G / 4× CPU)
make k6                # 3 scénarios séquentiels (~ 6 min)
make benchmark         # alias lighthouse + k6
```

### Données - seed, dump, restore

```bash
make seed-light       # dataset de dev (50 events, ~200 tickets, ≈ 3s)
make seed-realistic   # dataset formation (1500 events, 200k tickets, ≈ 18s)
make dump             # dump SQL gzippé → infra/seeds-dump/realistic.sql.gz
make restore          # restore depuis ce dump (cible < 30s)
```

`make dump` exécute `php artisan resonance:dump-database` dans le container
backend : `pg_dump --format=plain --no-owner --no-privileges --clean --if-exists`,
sortie gzippée vers `infra/seeds-dump/realistic.sql.gz` (overwrite silencieux,
pas de timestamp).

`make restore` exécute `php artisan resonance:restore-database` : drop +
recréation du schéma `public`, puis `gunzip -c … | psql -v ON_ERROR_STOP=1`.
Beaucoup plus rapide qu'un `seed-realistic` quand on veut juste revenir à
l'état "réaliste" entre deux ateliers.

### Comptes de test

Les deux seeders (light et realistic) provisionnent deux comptes stables,
utilisables pour parcourir l'application sans avoir à `register` :

| Email                  | Mot de passe | Rôle        | Données associées                                                       |
|------------------------|--------------|-------------|-------------------------------------------------------------------------|
| `visitor@demo.test`    | `password`   | `visitor`   | au moins 3 commandes (donc des billets visibles dans `/account/tickets`) |
| `organizer@demo.test`  | `password`   | `organizer` | possède des événements du seed (10 en `light`, ~75 en `realistic`)       |

Ces deux comptes sont également utilisables pour les tests Pest et pour les
captures d'écran des fiches d'ateliers.

## Documentation

- `docs/ateliers/` - fiches d'ateliers (à venir, phase 6 du scaffold).
- `docs/architecture.md` - vue d'ensemble technique (à venir).
- `docs/benchmarks/` - captures Lighthouse avant/après par atelier (à venir).

## Dépannage

### Bascule entre branches (`main` ↔ `final` ↔ `solution/jX-…`)

Trois artefacts ne suivent pas `git checkout` et peuvent rendre le
backend HS lors d'un changement de branche :

| Artefact | Symptôme | Correction |
|---|---|---|
| `backend/bootstrap/cache/*.php` (gitignoré) | Backend en boucle de restart, `include(…/HorizonServiceProvider.php): No such file` (ou autre provider absent) | `rm backend/bootstrap/cache/{config,services,packages,routes-v7,events}.php` |
| Image Docker `resonance/backend:dev` (cache local) | Nginx → 502 ; container `Up` mais `php-fpm` absent (CMD différent, port 9000 non écouté) | `docker compose build backend` puis `docker compose up -d backend` |
| `backend/.env` (gitignoré) | `SQLSTATE … could not translate host name "pgbouncer"` ou config queue/cache incohérente | `cp backend/.env.example backend/.env` (re-générer l'`APP_KEY` avec `make artisan cmd="key:generate"` si besoin) |

`final` introduit PgBouncer, Redis (queue+cache), Octane/FrankenPHP et
Horizon — tous absents de `main`. Le cache Laravel et l'image Docker
buildés sur `final` deviennent invalides sur `main` mais Docker et
Laravel les réutilisent silencieusement.

### Reset complet "starter"

Pour revenir à un état starter propre depuis n'importe quelle branche :

```bash
rm -f backend/bootstrap/cache/{config,services,packages,routes-v7,events}.php
cp backend/.env.example backend/.env
docker compose build backend
make up
make seed-light
```

