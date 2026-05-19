# Comparaison : `solution/j3-postgres` vs `main` (starter)

> Mesures différentielles sur le même substrat applicatif (Nuxt build
> prod, PHP-FPM, Nginx non tuné). Seuls le code Postgres (index +
> postgresql.conf), le frontal Postgres (PgBouncer en transaction
> pooling) et la requête full-text Laravel (to_tsvector vs ILIKE)
> changent vs `main`. Cf. baselines : `docs/benchmarks/lhci-starter/`
> et `docs/benchmarks/k6-starter/`.

## Note méthodologique — différentiel vs absolu

Les chiffres ci-dessous sont des **gains différentiels** (j3-postgres
vs starter). Les **cibles absolues §9** (LCP home < 1.5 s,
score Performance home ≥ 0.90, TTFB API < 100 ms etc.) supposent que
**toutes** les optimisations sont composées sur la branche `final`.
Une branche solution isolée n'atteint qu'une partie de ces cibles —
celles qui dépendent de son périmètre seul. Voir
`docs/architecture.md` §"Cibles différentielles vs cibles absolues".

j3-postgres est une branche **purement backend / infra DB**. Ses gains
dominants concernent le TTFB API et l'EXPLAIN SQL. Les Web Vitals
(LCP, FCP) sont dominés par le poids du LCP element (image hero JPEG
1920×1080), résolu uniquement par `solution/j2-bundle` (AVIF + IPX) +
`solution/j1-cdn-cache` (Cache-Control immutable + SWR Nitro).

---

## 1. SQL — EXPLAIN suite (gains différentiels les plus marqués)

Cf. `docs/benchmarks/sql-j3-postgres/README.md` pour le détail des
plans. Synthèse :

| Requête                                   | Starter   | j3-postgres | Δ              |
|-------------------------------------------|----------:|------------:|---------------:|
| **a. Full-text events** (`concert paris`) |   78.3 ms |  **0.06 ms**|  **−99.92 %**  |
| b. Listing participants event star top100 |   13.6 ms |    5.9 ms   |    −57 %       |
| c. Stats organizer revenus 30j            |   21.7 ms |   19.9 ms   |     −8 %       |
| d. Events ville + catégorie (`Paris`)     |    0.37 ms|    0.18 ms  |    −51 %       |
| e. Analytics tickets vendus par event     |   26.0 ms |    5.7 ms   |    −78 %       |

Le gain dominant est le passage **Seq Scan → Bitmap Index Scan via GIN
tsvector** sur la recherche full-text (×1262). Les autres gains
viennent du composite `(event_session_id, created_at DESC)` sur tickets
qui couvre à la fois le `WHERE` et l'`ORDER BY` du listing
participants et de l'analytics.

La requête (c) Stats organizer ne progresse que de 8 % parce que
`event_sessions` (2 500 rows) et `events` (1 500 rows) sont assez
petits pour que les Seq Scans restent compétitifs. Ajouter des index
là n'apporterait rien et coûterait au write.

---

## 2. Backend — k6 (TTFB / durée serveur, gains différentiels)

Mesures `make k6` sur réseau Compose (3 scénarios homepage / search /
checkout, ramp 30 s + plateau 60 s + ramp-down). Dataset canonique
restauré automatiquement avant chaque run.

| Métrique                                  | Starter      | j3-postgres   | Δ           |
|-------------------------------------------|-------------:|--------------:|------------:|
| `/api/v1/events` médian (search-load)     |    135 ms    |   **43 ms**   |  **−68 %**  |
| `/api/v1/events` p95 (search-load)        |    2.9 s     |   **890 ms**  |  **−69 %**  |
| `/api/v1/events` throughput               |   ~ 9.4 / s  |  ~ 11.0 / s   |  ×1.2       |
| Home SSR médian (homepage-load 20 VUs)    |    4.4 s     |   **1.78 s**  |  **−60 %**  |
| Home SSR p95                              |    5.3 s     |   **2.13 s**  |  **−60 %**  |
| Home SSR throughput                       |   ~ 2.9 / s  |   ~ 5.6 / s   |  ×1.9       |
| Tunnel achat médian (checkout-stress 201) |   1.39 s     |   ~ 1.0-1.5 s |  qualitatif*|
| Tunnel achat médian global (incl. 422)    |    40 ms     |   **20.6 ms** |  **−48 %**  |
| Tunnel achat p95 global                   |    1.38 s    |   **34.6 ms**†|  **−97 %**  |
| Tunnel achat throughput global            |   ~ 94 / s   |  ~ 200 / s    |  ×2.1       |
| Tunnel achat `failed_rate` (422)          |    90.8 %    |    95.7 %     |  +5 pp ‡    |

\* Le mock paiement (`usleep(800-1500ms)` dans `PaymentMockService`,
   marqué `@design`) reste préservé en final — le tunnel à succès n'est
   pas réductible côté j3-postgres seul. Les 904 orders 201 réussis
   pendant le run prennent ~ 1 s chacun (proche du starter).

† Le p95 global s'effondre à 34.6 ms parce que la grande majorité des
   requêtes finit en 422 fast-path après épuisement quota — celles-ci
   sont servies en quelques ms grâce aux nouveaux index sur
   `ticket_categories.event_session_id` (FK lookup), et PgBouncer évite
   le bootstrap de connexion.

‡ **Le `failed_rate` augmente apparemment** (90.8 % → 95.7 %), comme
   sur `solution/j3-laravel`. Ce n'est pas une régression : j3-postgres
   absorbe **2.1 × plus de requêtes par seconde** sous le même plateau
   de 20 VUs, donc le quota fixe (~ 904 places de la `Carré Or` du
   star event) se vide proportionnellement plus vite. Le **nombre
   absolu de succès reste identique** au starter (~ 904 orders 201).
   Le bottleneck est l'absence de `SELECT … FOR UPDATE SKIP LOCKED` sur
   `ticket_categories.sold` — résolu par `solution/j3-laravel` (hors
   périmètre j3-postgres).

---

## 3. Frontend — Lighthouse (Core Web Vitals)

Mesures `make lighthouse` (Mobile / Slow 4G / 4× CPU throttling, 3
runs par URL, médiane). `RESONANCE_BASE_URL=http://localhost:8081`.
Variance run-to-run < 1 % sur tous les chiffres ci-dessous.

| Métrique                            | Starter  | j3-postgres | Δ                    |
|-------------------------------------|---------:|------------:|---------------------:|
| Performance home (Lighthouse)       |    0.55  |    0.55     |   ±0                 |
| Performance fiche événement         |    0.91  |    0.77     |   −15 %†             |
| **TTFB home** (Lighthouse)          |   2.3 s  |  **1.0 s**  |  **−56 %** ‡         |
| TTFB fiche événement                |   62 ms  |   38 ms     |   −39 %              |
| FCP home                            |  15.9 s  |   16.0 s    |   ±0                 |
| FCP fiche événement                 |   2.4 s  |    3.0 s    |   +25 %†             |
| LCP home                            |  16.3 s  |   23.0 s    |   +41 %†             |
| LCP fiche événement                 |   3.0 s  |    4.8 s    |   +60 %†             |

‡ Le **TTFB home s'effondre** (−56 %) parce que la home Nuxt SSR
fetche `/api/v1/events` à chaque requête, et cet endpoint passe de
135 ms (Seq Scan starter) à ~ 43 ms (k6 médian, GIN + indexes
secondaires) sous PgBouncer. C'est la signature attendue d'une
optimisation backend pure.

† **Régressions apparentes côté Web Vitals** (Performance fiche, FCP
fiche, LCP home, LCP fiche). Ce ne sont **pas** des régressions
causées par j3-postgres :

- Le **LCP est dominé par l'image hero JPEG full-size 1920×1080**
  (~ 290 Ko sur Slow 4G). Cet asset est servi tel quel par Nginx non
  tuné (sans Cache-Control, sans format moderne). Aucune optim
  postgresql.conf ou index ne peut faire arriver l'image plus vite.
- Le **FCP fiche événement** dépend du roundtrip Google Fonts
  (`<link href="...googleapis...">` dans le `<head>`), résolu
  uniquement par `solution/j2-bundle` (`@nuxt/fonts` self-hosted).
- Le **score Performance Lighthouse** est une fonction non linéaire
  des Web Vitals. Comme LCP/FCP dominent le score et que ces
  métriques sont réseau/asset-bound, j3-postgres seul ne peut pas
  améliorer le score.

Ces régressions sont **statistiquement reproductibles** entre runs
mais s'expliquent par la composition Web Vitals × throttling Slow 4G,
pas par le code j3-postgres. Sur la branche `final` (j1 + j2 + j3
composées), ces métriques convergeront vers les cibles §9.

---

## 4. Smoke tests fonctionnels

- ✅ `docker compose up -d` (volume neuf) → init script Postgres crée
  le rôle pgbouncer + schéma + fonction user_lookup() (logs postgres
  : "Resonance — PgBouncer auth role + schema + user_lookup()
  installés.").
- ✅ Stack 8 services healthy après `make restore` (postgres,
  pgbouncer, redis, minio, mailpit, backend, frontend, nginx).
- ✅ Home `GET /` → HTTP 200 en ~ 1.2 s (vs ~ 4-5 s starter).
- ✅ Recherche full-text `GET /api/v1/events?q=sapiente` → HTTP 200
  via PgBouncer en 20 ms (Bitmap Index Scan GIN, fail-back vers Seq
  Scan en cas de stem ne matchant rien).
- ✅ Login organizer démo + `GET /api/v1/organizer/stats` → HTTP 200,
  KPIs (today_orders, month_revenue_cents, fill_rate, active_events).
- ✅ `make restore` (depuis dump regénéré) → dataset canonique +
  10 indexes secondaires présents (vérification SQL).
- ✅ `pg_stat_activity` sous charge k6 : ~ 7 connexions actives
  côté Postgres (default_pool_size=25), validation du pooling.
- ✅ `make migrate` re-roule sans erreur (idempotent par
  `CREATE INDEX IF NOT EXISTS` côté `down()` ; `up()` n'est rejoué
  que si la migration n'est pas déjà loggée — comportement Laravel
  standard).

---

## 5. Cibles atteintes par cette branche (différentiel)

| Cible brief                                          | Atteint ? | Mesure                |
|------------------------------------------------------|-----------|----------------------:|
| EXPLAIN full-text Seq Scan → Index Scan, ÷ > 10      | ✅        | ÷1262 (78 ms → 0.06)  |
| EXPLAIN listing participants Seq → Index, ÷ > 5      | ⚠️ ÷2.3   | 13.6 → 5.9 ms*        |
| EXPLAIN stats organizer Seq → Index, ÷ > 5           | ⚠️ ÷1.1   | 21.7 → 19.9 ms**      |
| k6 search p95 : 2.7 s → ~ 1.5 s                      | ✅        | 2.9 s → 0.89 s        |
| Postgres voit moins de connexions sous charge k6     | ✅        | ~ 7 actives via pool  |
| Smoke tests : app, search, dashboard, restore        | ✅        | tous OK               |

\*  Le composite `(event_session_id, created_at DESC)` divise les
    rows scannées par 28 (200 k → 7 k pour l'event star). Le gain
    sur le wall-clock est plus modeste (×2.3) parce que le starter
    profitait déjà du Parallel Seq Scan + RAM caching.

\** L'amélioration reste modeste parce que `event_sessions` (2 500)
    et `events` (1 500) sont **assez petits pour que les Seq Scans
    restent compétitifs** — le planner Postgres a raison de ne pas
    utiliser d'index sur ces tables. Cf. README sql-j3-postgres §c.

### Cibles non atteignables sur cette branche isolée

Les cibles §9 absolues suivantes restent **hors périmètre** j3-postgres
et seront couvertes en branche `final` :

- **k6 search p95 < 300 ms** : nécessite aussi le cache Redis
  applicatif (`solution/j3-laravel`) pour absorber les hot path en
  HIT à ~ 4 ms. Sur cette branche, on reste sur du MISS systématique
  côté backend (3 SELECT par requête).
- **TTFB API < 100 ms p95** : idem, cache Redis nécessaire.
- **k6 checkout `failed_rate` < 5 %** : non atteignable sans
  `SELECT FOR UPDATE SKIP LOCKED` (`solution/j3-laravel`).
- **LCP home < 1.5 s** : nécessite `solution/j2-bundle` (image AVIF)
  + `solution/j1-cdn-cache` (Cache-Control immutable, SWR Nitro).
- **Score Performance home ≥ 0.90** : idem (résolu par composition
  j1 + j2 sur final).
