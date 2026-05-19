# Architecture — Resonance (état starter)

> Ce document décrit l'architecture **du starter** (`main`), volontairement
> non-optimisée pour servir de point de départ pédagogique à la formation
> Performance Web sur 3 jours. Pour la cible optimisée, voir la branche `final`.
>
> Le starter tourne en mode
> **production-like NON optimisé** : build Nuxt prod, PHP-FPM derrière un
> Nginx volontairement non tuné. Plus de `nuxt dev` ni `php artisan serve` —
> ce n'est PAS un environnement de développement, c'est un environnement
> *de mesure*. Les `@perf-debt` sont des optimisations volontairement
> omises sur cet environnement de prod.

---

## 1. Vue d'ensemble

Resonance est une plateforme de billetterie d'événements culturels. Le starter
est livré sous forme d'une stack Docker Compose à 7 services :

| Service       | Image / Mode                                     | Port host  | Rôle                          |
|---------------|--------------------------------------------------|------------|-------------------------------|
| `postgres`    | `postgres:16-alpine`                             | 5432       | Base de données               |
| `redis`       | `redis:7-alpine`                                 | 6379       | Sessions Laravel uniquement   |
| `minio`       | `minio/minio:latest`                             | 9000/9001  | Stockage S3-compatible médias |
| `mailpit`     | `axllent/mailpit:latest`                         | 8025/1025  | Capture SMTP local            |
| `backend`     | image custom + **`php-fpm -F`** (port 9000 interne) | *(aucun)* | API Laravel 13.7 (FastCGI)    |
| `frontend`    | image multi-stage + **`node .output/server/index.mjs`** | 3000 | UI Nuxt 4 build prod (debug direct) |
| `nginx`       | `fholzer/nginx-brotli:latest`                    | **`NGINX_PORT` (défaut 8080)** | Frontal HTTP (proxy + fastcgi) |

Plus un service utilitaire **non démarré par défaut** :

| Service          | Image            | Profile | Rôle                               |
|------------------|------------------|---------|------------------------------------|
| `frontend-tools` | `node:20-alpine` | `tools` | Lint / typecheck éphémère (montage du source frontend). |

Les services applicatifs sont reliés par le réseau Docker
`resonance_resonance` (préfixe projet `${COMPOSE_PROJECT_NAME:-resonance}` ×
bridge `resonance`).

**Topologie HTTP** :

```
                   +-----------+
   browser  ─────► |   nginx   | $NGINX_PORT  (HTTP/1.1, gzip OFF, brotli OFF)
                   |   :80     | (défaut 8080)
                   +-----------+
                     │       │
                     │       └──► frontend (Nuxt SSR Node preview, :3000)
                     │
                     └──► fastcgi backend:9000 (PHP-FPM)
```

Le SSR Nuxt fetche l'API en passant lui aussi par Nginx
(`NUXT_API_BASE_INTERNAL=http://nginx/api/v1`), ce qui garde la chaîne
réelle SSR → Nginx → FPM dans la mesure.

---

## 2. Backend en PHP-FPM (non tuné)

Le backend tourne avec **PHP-FPM** (pool dynamic, 4-20 workers) derrière
Nginx, **pas** avec `php artisan serve`, **pas** avec Octane/FrankenPHP.

### Conséquences pédagogiques

- **Concurrence réelle** : 4-20 workers FPM répondent en parallèle. La
  charge `k6 search-load` montre des médianes en centaines de ms (vs
  plusieurs secondes en mono-process), mais p95 reste élevé sur les
  requêtes coûteuses (filtre `ILIKE` non indexé sur 1 200 events).
- **Pas d'OPcache, pas de JIT** : chaque requête recompile le PHP
  (configuré explicitement dans `infra/docker/backend.Dockerfile`,
  marqueur `@perf-debt`). TTFB structurellement élevé sur les endpoints
  lourds.
- **Pas de `config:cache`, `route:cache`, `view:cache`** : démarrage applicatif
  payé à chaque requête.
- **`QUEUE_CONNECTION=sync`** : génération PDF (`barryvdh/laravel-dompdf`) +
  envoi email exécutés synchronement dans `OrderController@store`. Tunnel
  d'achat à ~ 1.4 s par order réussi sous 20 VUs (cf. cible §9 spec :
  < 500 ms en final).

Ces choix sont marqués `@perf-debt` dans le code (cf. §5 ci-dessous).

---

## 3. Frontend en build production (non optimisé)

Le frontend tourne via une image multi-stage qui exécute `npm ci` +
`nuxt build` au build de l'image, puis lance `node .output/server/index.mjs`
au runtime. **Pas de hot reload, pas de bind-mount source.**

### Conséquences pédagogiques

- **Bundles minifiés** : pas de gonflement artificiel du *First Load JS*. 
  Les chiffres Lighthouse reflètent les vraies
  dégradations applicatives (Chart.js statique, images full-size, pas de
  `routeRules`, etc.).
- **Pas de compression côté Nitro ni côté Nginx** (`@perf-debt`).
- **Aucune `routeRules`** (`@perf-debt`) : tout est SSR pur, pas d'ISR ni
  SWR. Conséquence : la home Nuxt SSR fetche `/api/v1/events` (≈ 4 Mo de
  JSON sans pagination) à **chaque** visite, sans cache.

Pour modifier le code frontend pendant les ateliers : `make frontend-rebuild`
(rebuild + `up -d`). Pour lint/typecheck sans recompiler l'image :
`make frontend-lint` / `make frontend-typecheck` (containers éphémères
via le profil Compose `tools`).

---

## 4. Frontal Nginx — présent mais NON tuné

C'est le **point pédagogique central de J1**. Le starter dispose désormais
d'un frontal HTTP unique (Nginx, image `fholzer/nginx-brotli` qui contient
le module Brotli compilé), mais sa configuration est volontairement laissée
naïve : aucune des optimisations CDN n'est activée.

| Capacité HTTP                              | Starter | Final (`solution/j1-cdn-cache`) |
|--------------------------------------------|:---------------------:|:-------------------------------:|
| Présence d'un frontal HTTP                 | ✅                    | ✅                              |
| Compression `gzip`                         | ❌ `gzip off`         | ✅ `gzip on; gzip_types …`      |
| Compression `brotli`                       | ❌ modules présents, directives omises | ✅ `brotli on; brotli_static on;` |
| `Cache-Control` sur statiques `_nuxt/*`    | ❌                    | ✅ `max-age=31536000, immutable` |
| `Cache-Control` sur `/media/*`             | ❌                    | ✅ `max-age=86400, swr=604800`   |
| HTTP/2                                     | ❌ HTTP/1.1           | ✅                              |
| TLS                                        | ❌                    | ✅ (auto-signé en formation)    |
| Conditional GET (`ETag`, `Last-Modified`)  | partiel (proxy)       | ✅ tuné                         |
| Keepalive FastCGI                          | ❌                    | ✅                              |

### Pourquoi pas de Nginx tuné en starter ?

Le tuning HTTP (gzip, brotli, Cache-Control, HTTP/2) est
**l'objet** de la branche `solution/j1-cdn-cache`. Partir d'un Nginx
configuré « par défaut » force l'apprenant à **lire** la config et à y
ajouter chaque optim, plutôt que d'hériter d'un setup déjà optimisé.

**Décision** : on a estimé qu'une mesure « starter
sans frontal du tout » était trop éloignée d'un déploiement réel et
artificiellement dégradée. Avec Nginx présent mais non tuné, l'écart
starter ↔ branche solution isole les **optimisations HTTP** plutôt que
le **fait d'avoir un frontal**.

### Ports exposés

- `${NGINX_PORT}` (défaut 8080) — point d'entrée principal pour les
  utilisateurs et la mesure (Lighthouse CI + k6 utilisent cette URL via
  `RESONANCE_BASE_URL` dérivée de `NGINX_PORT`). Surchargeable dans
  `.env` (gitignored) si le port est déjà pris (e.g. autre service local).
- `${FRONTEND_PORT}` (défaut 3000) — accès direct Nuxt SSR pour debug
  (court-circuite Nginx, utile pour comparer les chiffres avec/sans
  frontal).
- `:8000` — **supprimé**. Le backend FPM n'écoute pas en HTTP, tout
  passe par Nginx /api/* (FastCGI).

---

## 5. Liste des dettes de perf intentionnelles (`@perf-debt`)

Recherche : `grep -rn "@perf-debt" backend/ frontend/ infra/`. Liste
exhaustive consolidée :

### Backend Laravel (~ 50 marqueurs)

- **N+1 systématique** dans les Resources (`OrderResource`, `EventResource`,
  `EventSessionResource`, `TicketResource`, `ParticipantResource`,
  `EventDetailResource`) — chaque relation lue sans `whenLoaded()`.
  → résolu en `solution/j3-laravel`.
- **Aucune pagination** sur 4 endpoints listing (`/events`,
  `/organizer/events`, `/me/tickets`, `/organizer/events/{id}/participants`).
  Réponse plate `{ data: [...] }`, pas de bloc `meta`.
  → résolu en `solution/j2-frontend` (cursor + virtualisation).
- **Aucun cache applicatif** : pas de `Cache::remember()`. Redis utilisé
  uniquement pour les sessions Laravel. → résolu en `solution/j3-laravel`.
- **`QUEUE_CONNECTION=sync`** : PDF dompdf et SMTP synchrones dans
  `OrderController@store`. → résolu en `solution/j3-laravel`.
- **PaymentMockService** : `usleep(rand(800, 1500) * 1000)` bloquant.
  *Préservé en final* (réalisme PSP), mais le reste du flow part en queue.
- **Pas de `SELECT FOR UPDATE SKIP LOCKED`** sur `ticket_categories.sold` :
  race condition possible (et k6 `checkout-stress` l'expose désormais
  sous FPM concurrent — épuisement quota visible en quelques secondes).
  → résolu en `solution/j3-laravel`.
- **Aucun index secondaire Postgres** au-delà des PK / FK / UNIQUE(slug).
  → résolu en `solution/j3-postgres`.
- **`postgresql.conf` par défaut** (`shared_buffers=128MB`).
  → résolu en `solution/j3-postgres`.
- **Pas de PgBouncer**. → résolu en `solution/j3-postgres`.
- **Pas d'OPcache, FPM standard non tuné, pas d'Octane**. → résolu en
  `solution/j3-laravel` (`infra/docker/backend.Dockerfile`).

### Frontend Nuxt (~ 17 marqueurs)

- **Chart.js importé en statique** dans `layouts/organizer.vue` :
  ~200 Ko ajoutés au bundle de toutes les pages organisateur.
  → résolu en `solution/j2-bundle` (import dynamique).
- **Liste participants non virtualisée** : `v-for` sur 7 000 lignes pour
  l'event star (id 600). DOM massif, INP > 500 ms.
  → résolu en `solution/j2-dashboard` (`vue-virtual-scroller`).
- **Dashboard organisateur** : `ref` global unique sur tout l'état, polling
  10 s qui invalide tout l'arbre Vue à chaque tick.
  → résolu en `solution/j2-dashboard` (`shallowRef`, `v-memo`).
- **Pas de `@nuxt/image`** : images servies en JPEG full-size (1920×1080,
  ~400 Ko) sans transformation, sans formats modernes.
  → résolu en `solution/j2-bundle`.
- **Pas de `@nuxt/fonts`** : Google Fonts via `<link>` sans `font-display: swap`.
  → résolu en `solution/j2-bundle`.
- **Pas de `routeRules`** : tout SSR à chaque requête, pas d'ISR ni SWR.
  → résolu en `solution/j1-cdn-cache`.
- **Pas de `<ClientOnly>` ni de lazy hydration** sur le sélecteur de billetterie
  de la fiche événement. → résolu en `solution/j2-bundle`.

### Infrastructure ( ~ 6 marqueurs)

- **`gzip off`** dans `infra/nginx/nginx.conf`. → résolu en `solution/j1-cdn-cache`.
- **Brotli compilé mais non activé** (modules présents, directives omises).
  → résolu en `solution/j1-cdn-cache`.
- **HTTP/1.1 only** (`listen 80;` sans `http2`). → résolu en `solution/j1-cdn-cache`.
- **Aucun `Cache-Control`** sur `/_nuxt/*` ni `/media/*`. → résolu en
  `solution/j1-cdn-cache`.
- **Pas de `keepalive` ni `fastcgi_keep_conn`** sur l'upstream FPM.
  → résolu en `solution/j3-laravel` (Octane) ou `solution/j1-cdn-cache`.

---

## 6. Cibles chiffrées attendues


| Métrique                                | Starter mesuré  | Final (cible) |
|-----------------------------------------|----------------:|--------------:|
| LCP fiche événement (`/events/{slug}`)  |        4.8 s    |     < 1.5 s   |
| Score Performance home Lighthouse       |        0.55     |        ≥ 0.90 |
| Score Performance fiche Lighthouse      |        0.77     |        ≥ 0.90 |
| TTFB API `/api/v1/events` (k6 médian)   |        125 ms   |     < 100 ms  |
| Tunnel achat (succès médian)            |        1.39 s   |     < 500 ms  |

Ces cibles s'appliquent **sur le même substrat prod-like** : les optims
J1/J2/J3 sont mesurées en relatif par rapport au starter,
pas en remplaçant un dev par un prod.

### Cibles différentielles vs cibles absolues

Le tableau ci-dessus liste des **cibles absolues** : elles supposent
que **toutes les optimisations sont en place** simultanément. Cet état
n'existe que sur la branche `final` (merge des 5 branches solution).

**Une branche `solution/jX-name` isolée n'atteint qu'une partie** de
ces cibles, celles qui dépendent de son périmètre seul. Les
optimisations se composent — exemple vérifié empiriquement sur
`solution/j2-bundle` :

- LCP home cible §9 = **< 1.5 s**.
- LCP home mesuré sur `solution/j2-bundle` = **5.6 s** (depuis 16.3 s
  starter, soit −66 %, gain réel et substantiel).
- Pourquoi 5.6 s et pas 1.5 s ? J2 réduit le **poids** du LCP element
  (image AVIF au lieu de JPEG full-size) et débloque le **FCP**
  (suppression du roundtrip Google Fonts), mais le **TTFB SSR** reste
  à ~ 1.9 s parce que la home Nuxt fetche `/api/v1/events` à chaque
  requête sans cache. Le TTFB ne sera attaqué qu'en mergeant
  `solution/j1-cdn-cache` (SWR Nitro → cache hit ~ 0 ms après le 1er).
  Sur `final`, j1 + j2 composent : TTFB ~ 0 ms (j1) + image légère
  (j2) → LCP ~ 1.5 s atteignable.

**Implication pour le brief de chaque branche solution** : énoncer
les cibles en termes **différentiels** (gain vs starter sur le
périmètre propre), pas en cibles absolues §9. Les cibles absolues §9
sont vérifiées **uniquement** en branche `final`.

**Implication pour les rapports de benchmark** :
`docs/benchmarks/<branch>-comparison.md` est le document d'autorité
pour valider une branche solution — il présente des deltas
(starter ↔ branche). Ne pas comparer une branche solution isolée à la
colonne "Final (cible)" du tableau ci-dessus, c'est un faux négatif
systématique.

---

## 7. Outils de mesure intégrés

Voir aussi `docs/benchmarks/README.md` pour la procédure complète.

- **Lighthouse CI** (`@lhci/cli` à la racine, configuration dans
  `load-tests/lighthouse/lighthouserc.cjs`) : audit Mobile / Slow 4G /
  4× CPU throttling, 3 runs par URL, médiane. Cible Make : `make lighthouse`.
  Couvre automatiquement `/` et `/events/{star-slug}` ; les pages
  authentifiées (`/organizer/dashboard`, `/organizer/events/{id}/participants`)
  sont mesurées **manuellement** depuis Chrome DevTools — procédure dans
  `docs/benchmarks/README.md`.
- **k6** (container `grafana/k6:latest`, scénarios dans `load-tests/k6/`) :
  3 scénarios — `homepage-load.js`, `search-load.js`, `checkout-stress.js`.
  Cible Make : `make k6`. Toutes les charges sont dirigées vers Nginx
  (`http://nginx` interne du réseau Compose ; en standalone, fallback
  `http://localhost:${NGINX_PORT}`).
- **`nuxi analyze`** (frontend) pour l'analyse de bundle : ajouté pendant
  `solution/j2-bundle`.

### Reproductibilité — `make restore` (DB + MinIO)

`make lighthouse` et `make k6` ont `make restore` en pré-requis
automatique. Cette cible **réinitialise deux états** côté stack :

1. **Base PostgreSQL** : `php artisan resonance:restore-database`
   réimporte le dump SQL gzippé `infra/seeds-dump/realistic.sql.gz`
   (~ 1 s sur le dataset realistic).
2. **Pool d'images MinIO** : `php artisan resonance:ensure-media-pool
   --quiet-when-complete` vérifie que les ~30 placeholders
   `seed-pool/img-*.jpg` sont bien dans le bucket `resonance` de MinIO,
   et les reupload depuis `infra/seeds-dump/media/` (cache local) au
   besoin.

Les deux steps sont nécessaires parce que **le dump SQL n'inclut PAS
les binaires MinIO** (`media.path` est restauré, mais le volume
Docker `minio_data` qui héberge les `.jpg` est totalement séparé).
Sans le step 2, un volume `minio_data` recréé (par exemple
`docker compose down -v` ou première install) laissait toutes les
images en 404 → mesures Lighthouse silencieusement faussées (LCP image
perdu, total byte weight sous-évalué de plusieurs Mo). Le step 2 est
**idempotent** : si tout est déjà là, c'est ~ 30 HEAD requests
(~ 100 ms) avant de sortir.

Les baselines starter sont versionnées dans
`docs/benchmarks/lhci-starter/` (Lighthouse) et
`docs/benchmarks/k6-starter/` (k6). Chaque branche `solution/jX-name`
publiera son delta dans `docs/benchmarks/jX-name/`.

---

## 8. Ce qui n'est PAS dans le starter (et où ça arrive)

| Composant / Optimisation                  | Branche introductrice            |
|-------------------------------------------|----------------------------------|
| Tuning Nginx (gzip, brotli, HTTP/2, Cache-Control) | `solution/j1-cdn-cache`  |
| `routeRules` Nuxt (ISR / SWR)             | `solution/j1-cdn-cache`          |
| Simulation CDN / Varnish                  | `solution/j1-cdn-cache`          |
| `@nuxt/image`, `@nuxt/fonts`              | `solution/j2-bundle`             |
| Code splitting Chart.js, lazy hydration   | `solution/j2-bundle`             |
| `vue-virtual-scroller` participants       | `solution/j2-dashboard`          |
| `shallowRef`, `v-memo` dashboard          | `solution/j2-dashboard`          |
| Pagination cursor (front + back)          | `solution/j2-frontend`           |
| Eager loading + API Resources strictes    | `solution/j3-laravel`            |
| Cache Redis applicatif                    | `solution/j3-laravel`            |
| Queues Redis + Horizon                    | `solution/j3-laravel`            |
| Octane + FrankenPHP, OPcache, JIT         | `solution/j3-laravel`            |
| Index Postgres + tuning `postgresql.conf` | `solution/j3-postgres`           |
| PgBouncer                                 | `solution/j3-postgres`           |
| `SELECT FOR UPDATE SKIP LOCKED`           | `solution/j3-laravel`            |

La branche `final` agrège l'ensemble.
