# Benchmarks Resonance

Ce dossier rassemble les **mesures de performance** de chaque état du projet.

Le starter tourne en mode prod-like
non optimisé (Nginx + PHP-FPM + Nuxt build), pas en `nuxt dev` +
`artisan serve`. Toutes les baselines suivent cette convention.

```
docs/benchmarks/
├── README.md                    # ce fichier
├── lhci-starter/                # baseline Lighthouse de la branche main
├── k6-starter/                  # baseline k6 de la branche main
├── lighthouse-latest/           # ⚠ volatil, gitignoré — sortie de `make lighthouse`
└── k6-latest/                   # ⚠ volatil, gitignoré — sortie de `make k6`
```

À chaque branche `solution/jX-name`, ajouter `docs/benchmarks/jX-name/` avec
les rapports avant/après pour pouvoir comparer.

## Reproductibilité — pre-hook `make restore`

**Toute mesure réinitialise la base ET le pool d'images MinIO à un état
canonique** pour garantir la reproductibilité : `make lighthouse` et
`make k6` ont `make restore` en pré-requis automatique. `make restore`
fait deux choses :

1. **Restore SQL** : `php artisan resonance:restore-database` réimporte
   `infra/seeds-dump/realistic.sql.gz` (~ 1 s).
2. **Restore pool MinIO** : `php artisan resonance:ensure-media-pool
   --quiet-when-complete` vérifie que les ~30 placeholders
   `seed-pool/img-*.jpg` sont bien dans MinIO, et les reupload depuis
   `infra/seeds-dump/media/` (cache local) si besoin.

Pourquoi le step 2 est nécessaire : le dump SQL ne contient **que** le
schéma logique (`media.path = 'seed-pool/img-…jpg'`), pas les binaires
MinIO (qui vivent dans le volume Docker `minio_data`, séparé).
Avant l'introduction du step 2, un volume `minio_data` recréé (par
exemple `docker compose down -v` ou première installation) laissait le
bucket vide après `make restore` ; toutes les images renvoyaient 404,
et les mesures Lighthouse étaient **silencieusement faussées** (LCP
image perdu, total byte weight sous-évalué de plusieurs Mo). Le step 2
est idempotent : si tout le pool est déjà uploadé, c'est ~30 HEAD
requests (~ 100 ms) avant de sortir sans rien dire.

Sans le step 1, le second run de `make k6` héritait d'un quota épuisé
par le premier (le scénario `checkout-stress` consomme
`ticket_categories.sold` qui est persisté), et les chiffres devenaient
incomparables (failed_rate qui passait de 91 % à 97 %, médiane qui
chutait de 1.4 s à ~30 ms parce que tout finissait en 422 immédiat).

**Variance vérifiée** entre 2 runs successifs avec pre-hook : < 5 % sur
toutes les métriques k6 (cf. `k6-starter/summary.md` §5).

Pour itérer rapidement sans reset (typiquement : développement d'une
branche solution où on veut juger l'effet d'une modif sans payer le
restore à chaque fois) :

```bash
make lighthouse-no-restore
make k6-no-restore
```

⚠ Les chiffres de runs successifs sans restore ne sont pas comparables.

---

## 1. Lighthouse CI

Cible automatisée : audit Mobile / Slow 4G / CPU 4× sur les 2 écrans
**publics** critiques.

```bash
make lighthouse
```

URLs auditées automatiquement (host = `${RESONANCE_BASE_URL}`, dérivée de
`NGINX_PORT` ; défaut 8080) :

- `${RESONANCE_BASE_URL}/` — écran d'accueil (LCP candidate : hero image
  starter en JPEG 1920×1080 ~ 400 Ko sans `<NuxtImg>`).
- `${RESONANCE_BASE_URL}/events/{star-slug}` — fiche de l'event ayant le
  plus de tickets vendus dans le seed (résolu via SQL par
  `load-tests/scripts/resolve-star-slug.sh`).

> Si le port 8080 est déjà occupé sur ta machine, surcharge `NGINX_PORT`
> dans `.env` (le Makefile dérive automatiquement `RESONANCE_BASE_URL`)
> ou exporte explicitement `RESONANCE_BASE_URL=http://localhost:XXXX`
> avant `make lighthouse` / `make k6`.

### Pages auth-required — mesure manuelle

Lighthouse CI ne mesure pas automatiquement `/organizer/dashboard` et
`/organizer/events/{id}/participants` car l'auth Resonance utilise un
Bearer Sanctum stocké en `localStorage` (cf. mémoire `feedback_nuxt_ssr_auth`),
pas un cookie HttpOnly — `lhci collect --url=...` se contenterait de charger
l'URL et serait redirigé vers `/login`.

Procédure copier-coller pour la mesure manuelle (Chrome DevTools) :

1. **Démarrer la stack** et seeder avec le dataset réaliste si pas déjà fait :

   ```bash
   make up
   make restore   # ou make seed-realistic
   ```

2. **Se connecter** dans Chrome avec le compte démo organisateur :

   - URL : `http://localhost:${NGINX_PORT}/login`
   - Email : `organizer@demo.test`
   - Mot de passe : `password`

3. **Activer l'auditeur** :

   - Ouvrir DevTools (F12).
   - Onglet **Lighthouse**.
   - Mode : **Navigation (Default)**.
   - Catégories : **Performance** uniquement (pour aller vite).
   - Device : **Mobile**.
   - Throttling : **Simulated throttling** (par défaut Slow 4G + 4× CPU).

4. **Mesurer le dashboard** (`/organizer/dashboard`) :

   - Naviguer sur la page. Attendre que les 4 KPIs et la courbe Chart.js
     soient affichés (sinon Lighthouse ne mesure rien d'utile).
   - Cliquer **Analyze page load**.
   - Attention : le polling 10s du dashboard parasite légèrement la mesure
     LCP/INP. Pour une mesure stable, ouvrir la console et exécuter
     `clearInterval(...)` sur l'interval de polling, ou simplement
     accepter le bruit (~50ms d'INP).

5. **Mesurer les participants** (`/organizer/events/{id}/participants`) :

   - Récupérer l'`id` de l'event star de l'organizer démo (event 600 dans
     le seed actuel) :

     ```bash
     # ID stable conventionné par le seeder.
     EVENT_ID=600
     ```

   - URL : `http://localhost:${NGINX_PORT}/organizer/events/600/participants`.
   - Attendre que la table soit complètement rendue (≈ 7 000 lignes en
     starter — c'est le point pédagogique). Le navigateur peut "freeze"
     quelques secondes : c'est l'INP starter qu'on cherche à mesurer.
   - Lancer **Analyze page load**.

6. **Sauvegarder le rapport** :

   - Cliquer sur l'icône export (en haut à droite du panel Lighthouse).
   - **Save as HTML**.
   - Renommer en `dashboard-mobile.html` ou `participants-mobile.html`.
   - Déposer dans `docs/benchmarks/lhci-starter-manual/`.

> **TODO automatisation** : ces deux pages pourraient être auditées
> automatiquement via `puppeteerScript` dans `lighthouserc.cjs` (POST
> `/api/v1/auth/login` puis `page.evaluate(t => localStorage.setItem('auth_token', t))`).
> Voir https://github.com/GoogleChrome/lighthouse-ci/blob/main/docs/configuration.md#authentication
> Pour le moment, mesure manuelle suffisante pour la baseline pédagogique.

### Sortie de `make lighthouse`

Les rapports HTML + JSON sont écrits dans `docs/benchmarks/lighthouse-latest/`
(gitignoré, regénéré à chaque run). Pour figer une baseline, copier les
deux runs représentatifs dans `docs/benchmarks/<nom>/` et committer (cf.
`docs/benchmarks/lhci-starter/` pour la baseline de référence).

Exemple de copie pour figer la baseline starter (run représentatif) :

```bash
make lighthouse
mkdir -p docs/benchmarks/lhci-starter
# Identifier le run représentatif via manifest.json (isRepresentativeRun=true)
# puis copier le HTML + JSON correspondants en home-mobile.* / event-detail-mobile.*
cp docs/benchmarks/lighthouse-latest/manifest.json docs/benchmarks/lhci-starter/
```

---

## 2. k6 — scénarios de charge

Cible automatisée : 3 scénarios séquentiels via container `grafana/k6:latest`
sur le réseau Compose `resonance_resonance`. Les VUs
frappent **`http://nginx`** (port 80 interne) qui orchestre `/api/*` →
`backend:9000` (FastCGI) et le reste → `frontend:3000` (Nuxt SSR Node preview).

```bash
make k6
```

Scénarios (cf. `load-tests/k6/`) :

| Fichier                | Cible                          | Charge            | Durée totale | Sujet pédagogique |
|------------------------|--------------------------------|-------------------|--------------|-------------------|
| `homepage-load.js`     | `GET /` (Nuxt SSR via Nginx)   | 20 VUs plateau    | ~ 2 min      | TTFB / cache HTML / payload SSR |
| `search-load.js`       | `GET /api/v1/events?...` x N   | 30 VUs plateau    | ~ 2 min      | filtres SQL non-indexés         |
| `checkout-stress.js`   | tunnel d'achat concurrentiel   | 20 VUs plateau    | ~ 2 min      | concurrence FPM, épuisement quota, tunnel synchrone (J3) |

### Sortie de `make k6`

Les sommaires k6 (`*-summary.json`) sont écrits dans
`docs/benchmarks/k6-latest/` (gitignoré). Pour figer une baseline :

```bash
make k6
cp -v docs/benchmarks/k6-latest/*.json docs/benchmarks/k6-starter/
```

---

## 3. EXPLAIN suite SQL (solution/j3-postgres)

Cible Make introduite en `solution/j3-postgres` pour mesurer l'apport
des index secondaires + GIN tsvector + tuning postgresql.conf sur les
hot paths.

```bash
make explain          # restore DB → exécute la suite EXPLAIN
make explain-no-restore  # itération rapide, sans reset DB
```

La suite (`load-tests/sql/explain-suite.sql`) résout dynamiquement
l'event star et l'organizer démo via `\gset`, puis lance 5 EXPLAIN
(ANALYZE, BUFFERS) :

1. Recherche full-text events (`to_tsvector @@ plainto_tsquery`).
2. Listing participants — event star (top 100 derniers tickets).
3. Stats organizer revenus 30 j (cascade joins).
4. Recherche events par ville + catégorie.
5. Comptage tickets vendus par event (analytics).

Sortie : `docs/benchmarks/sql-j3-postgres/run-<timestamp>.txt` +
alias `latest.txt`. Cible `make explain` exécutée AVANT la migration
(BEFORE = baseline starter sans index) puis APRÈS (`php artisan migrate`).

### Procédure manuelle complémentaire

Pour analyser une requête personnalisée sur un état arbitraire :

```bash
# 1. Ouvrir une session psql interactive (sur Postgres direct).
docker compose exec postgres psql -U resonance -d resonance

# 2. Activer le timing fin-grain.
\timing on

# 3. EXPLAIN ANALYZE BUFFERS sur la requête à étudier.
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT … ;

# 4. Copier la sortie dans docs/benchmarks/sql-j3-postgres/manual/<nom>.txt
#    (le sous-dossier manual/ est créé par make explain).
```

⚠ **Toujours via Postgres direct**, jamais via PgBouncer (port 6432) :
le mode transaction pooling n'aime pas les sessions interactives
multi-statements de psql.

---

## 4. Cibles chiffrées attendues

| Métrique                                 | Starter mesuré | Final (cible) |
|------------------------------------------|---------------:|--------------:|
| LCP fiche événement (`/events/{slug}`)   |          4.8 s |     < 1.5 s   |
| INP dashboard organisateur               |       > 500 ms |     < 200 ms  |
| TTFB API `/api/v1/events` (médian)       |        125 ms  |     < 100 ms  |
| First Load JS home (gzipped)             |       > 400 Ko |    < 150 Ko   |
| Tunnel achat (succès médian)             |         1.39 s |     < 500 ms  |

ℹ Le starter tourne en **mode prod-like NON optimisé**
(Nuxt build prod + PHP-FPM + Nginx non tuné). Les `@perf-debt` qui restent
sont applicatifs et sont l'objet des branches `solution/jX-name`.

---

## 4. Convention `data-row` pour le DOM count

Les branches solution qui virtualisent des listes (à partir de
`solution/j2-dashboard` : `RecycleScroller` sur `/organizer/events/{id}/participants`)
**doivent** poser un attribut `data-row` sur chaque ligne effectivement
rendue dans le DOM (la slot du scroller, pas le header de table).

### Selector de mesure

```js
document.querySelectorAll('[data-row]').length
```

À exécuter dans la console DevTools de la page mesurée. Donne le
nombre de rows réellement matérialisées dans le DOM à l'instant T —
pour un scroller bien dimensionné (~20 rows visibles + buffer 200px),
attendre ~25-35.

### Pourquoi pas `<tr>` ?

`vue-virtual-scroller` (et la plupart des virtualizers Vue) ne
supportent pas la sémantique `<table>/<tbody>` parce qu'ils ont besoin
de positionner leurs items en absolu. La table HTML est donc remplacée
par une grille CSS, et le selector `tr` retournerait 0 — un faux
négatif si on lit ça naïvement (« plus de rows ! »). Le compteur
`<tr>` reste utile pour **détecter le starter** (qui est en `<table>`)
mais n'est pas portable à la version optimisée.

`[data-row]` est neutre vis-à-vis du markup : qu'on rende des
`<tr role="row">`, des `<div role="row">` ou autre, le compteur fonctionne.

### Avant / après attendu

| État                       | `tr` total | `[data-row]` | DOM nodes total |
|----------------------------|-----------:|-------------:|----------------:|
| Starter (event 600, 7000 t) |   7 001   |        0     |       42 062    |
| `solution/j2-dashboard`     |     0     |       ~30    |        ~ 226    |

Cf. `docs/benchmarks/j2-dashboard-comparison.md` pour les chiffres
exacts et `docs/benchmarks/lhci-j2-dashboard/manual/` pour les
mesures brutes.
