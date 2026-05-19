# Atelier J3 - Postgres : index, tuning, PgBouncer

> **Branche solution** : `solution/j3-postgres`
> **Pré-requis** : avoir suivi les ateliers précédents OU connaître le
> contenu de `docs/architecture.md`

> **Note méthodologique - cibles différentielles vs absolues**
> (cf.`docs/architecture.md` §6
> "Cibles différentielles vs cibles absolues").
>
> Cet atelier livre des **gains différentiels** - l'apport propre de
> j3-postgres mesuré contre le starter, périmètre Postgres + PgBouncer
> + requête full-text Laravel. Les **cibles absolues** (LCP home
> < 1.5 s, k6 search p95 < 300 ms, etc.) supposent la composition de
> **toutes** les optimisations sur la branche `final`. Cas d'école sur
> cette branche : le **TTFB API s'effondre** (135 ms → 43 ms p95
> 2.9 s → 890 ms) mais les Web Vitals Lighthouse (LCP home, score
> Performance) régressent apparemment. Ce n'est pas une régression
> j3-postgres - c'est l'absence de cache HTTP (`solution/j1-cdn-cache`)
> et d'image AVIF (`solution/j2-bundle`). C'est précisément ce que la
> note méthodologique veut éviter de prendre pour un échec de cette
> branche : c'est la signature attendue d'optimisations qui se composent.

### Ce que cet atelier améliore (gains différentiels mesurés)

Mesures vs `main` (cf. `docs/benchmarks/j3-postgres-comparison.md`,
mêmes conditions throttling Mobile / Slow 4G / 4× CPU pour Lighthouse,
mêmes profils 20-30 VUs pour k6) :

#### SQL - EXPLAIN ANALYZE BUFFERS

| Requête                                   | Starter   | j3-postgres | Δ              |
|-------------------------------------------|----------:|------------:|---------------:|
| **Full-text events** (`concert paris`)    |   78.3 ms |  **0.06 ms**| **−99.92 %**   |
| Listing participants event star top 100   |   13.6 ms |   5.9 ms    |   −57 %        |
| Stats organizer revenus 30j               |   21.7 ms |   19.9 ms   |    −8 %*       |
| Events ville + catégorie (`Paris`)        |    0.37 ms|   0.18 ms   |   −51 %        |
| Analytics tickets vendus par event        |   26.0 ms |   5.7 ms    |   −78 %        |

\* event_sessions (2 500 rows) et events (1 500 rows) assez petits
pour que les Seq Scans restent compétitifs - le planner Postgres a
raison de ne pas utiliser d'index sur ces tables. Cf. `docs/benchmarks/sql-j3-postgres/README.md` §c.

#### Backend (k6 - TTFB / durée serveur)

| Métrique                            | Starter      | j3-postgres   | Δ           |
|-------------------------------------|-------------:|--------------:|------------:|
| `/api/v1/events` médian (search)    |    135 ms    |   **43 ms**   |  **−68 %**  |
| `/api/v1/events` p95 (search)       |    2.9 s     |   **890 ms**  |  **−69 %**  |
| Home SSR médian (20 VUs)            |    4.4 s     |   **1.78 s**  |  **−60 %**  |
| Home SSR p95                        |    5.3 s     |   2.13 s      |   −60 %     |
| Tunnel achat throughput global      |   ~ 94 / s   |  **~ 200/s**  |   ×2.1      |
| Tunnel achat p95 global             |    1.38 s    |   **34.6 ms** |   −97 % ‡   |

‡ p95 dominé par le 422 fast-path (quota épuisé) - voir comparison.md
pour la lecture complète.

#### Frontend (Lighthouse)

| Métrique                            | Starter   | j3-postgres | Δ           |
|-------------------------------------|----------:|------------:|------------:|
| **TTFB home** (Lighthouse)          |    2.3 s  |  **1.0 s**  |  **−56 %**  |
| TTFB fiche événement                |    62 ms  |   38 ms     |   −39 %     |
| LCP home                            |   16.3 s  |   23.0 s    |   +41 %†    |
| LCP fiche événement                 |    3.0 s  |    4.8 s    |   +60 %†    |

† Régressions apparentes documentées dans
`docs/benchmarks/j3-postgres-comparison.md` §3 - LCP dominé par image
hero JPEG full-size (résolu par j2-bundle + j1-cdn-cache composés).

L'atelier valide **3 leviers** : index secondaires + GIN tsvector,
postgresql.conf tuné, PgBouncer en frontal en transaction pooling.

### Ce qui n'est PAS dans le périmètre

Les cibles suivantes restent **non atteignables** sur cette branche
isolée et seront couvertes en branche `final` :

- **k6 search p95 < 300 ms** : nécessite aussi cache Redis applicatif
  (`solution/j3-laravel`) pour absorber les hot paths en HIT à ~ 4 ms.
  Sur cette branche, on reste sur du MISS systématique côté backend
  (3 SELECT par requête après eager loading qui est sur une autre
  branche).
- **TTFB API < 100 ms p95** : idem.
- **k6 checkout `failed_rate` < 5 %** : non atteignable sans
  `SELECT … FOR UPDATE SKIP LOCKED` sur `ticket_categories.sold`,
  qui est dans `solution/j3-laravel`.
- **LCP home < 1.5 s, score Performance home ≥ 0.90** : hors
  périmètre backend. Couverts par `solution/j2-bundle` (image AVIF
  + IPX) + `solution/j1-cdn-cache` (Cache-Control immutable + SWR
  Nitro).

Ces gains additionnels arrivent par composition lors du merge des
solutions sur la branche `final`.

## 1. Objectif pédagogique

Réduire le **TTFB API**, le **coût des requêtes hot path** sur le
dataset 200 k tickets, et le **coût de bootstrap connexion Postgres**
sous charge concurrente, sans toucher au frontend ni au runtime
applicatif (qui sont l'objet d'autres branches solution). Trois
leviers complémentaires :

1. **Index secondaires + GIN tsvector** : 9 index couvrant les hot
   paths (events listing, recherche full-text français, sessions
   join, tickets par session, orders organizer). Cible : EXPLAIN
   passe de Seq Scan à Index Scan / Bitmap Index Scan sur les
   requêtes représentatives.
2. **postgresql.conf tuné** : remplace les defaults stricts de
   l'image alpine (shared_buffers=128MB, work_mem=4MB) par des
   valeurs adaptées au dataset (1 GB / 16 MB). Élargit l'effective
   cache size pour que le planner privilégie les Index Scans.
3. **PgBouncer en transaction pooling** : permet à 4-20 workers FPM
   de partager 25 backends Postgres au lieu d'en ouvrir un chacun.
   Réduit la pression sur Postgres sous charge, et amortit le
   bootstrap de connexion (handshake SCRAM, charge config). Auth
   via SCRAM passthrough (pas de hash committé).

Résultat composé mesuré (cf.
`docs/benchmarks/j3-postgres-comparison.md`) :

- **EXPLAIN full-text** : 78 ms (Seq Scan) → **0.06 ms** (Bitmap
  Index Scan via GIN), ×1262.
- **k6 search-load p95** : 2.9 s → **890 ms** (−69 %).
- **k6 home SSR médian** : 4.4 s → **1.78 s** (−60 %).
- **Lighthouse TTFB home** : 2.3 s → **1.0 s** (−56 %).

## 2. Énoncé pas-à-pas (depuis `main`)

```bash
git checkout main
git pull
git checkout -b mon-atelier-j3-postgres
```

### Étape 1 - Migration des index secondaires

Créez `backend/database/migrations/2026_05_07_140100_add_performance_indexes.php`
avec 9 index `DB::statement(...)` :

```php
// events - partial sur status='published'
DB::statement(<<<'SQL'
    CREATE INDEX idx_events_status_published
        ON events (status, published_at DESC)
        WHERE status = 'published'
SQL);
DB::statement('CREATE INDEX idx_events_city ON events (city)');
DB::statement(<<<'SQL'
    CREATE INDEX idx_events_category_published
        ON events (category, published_at DESC)
SQL);

// GIN tsvector français - l'expression doit être identique à celle
// utilisée par EventController@index sinon le planner ne choisit
// pas l'index !
DB::statement(<<<'SQL'
    CREATE INDEX idx_events_search
        ON events USING gin (to_tsvector('french', title || ' ' || description))
SQL);

// event_sessions, tickets, orders, media - voir migration commitée.
```

**Note production - `CREATE INDEX CONCURRENTLY`** (transférable
hors formation) : sur une base live, on utiliserait
`CREATE INDEX CONCURRENTLY` pour ne pas bloquer les SELECT/INSERT/
UPDATE avec un ACCESS EXCLUSIVE lock. CONCURRENTLY est **incompatible**
avec le wrapping transactionnel par défaut des migrations Laravel
(une migration s'exécute dans une transaction, et CONCURRENTLY
exige d'être hors transaction). En atelier, on utilise CREATE
INDEX bloquant (lock court ~ secondes sur tickets 200 k). En prod,
trois options :

- (a) `public bool $withinTransaction = false;` dans la migration +
  `CREATE INDEX CONCURRENTLY`.
- (b) Migration Laravel pour le DDL "rapide" + script SQL séparé
  pour les index lourds (à exécuter manuellement par DBA).
- (c) Migration "no-op" enregistrée dans la table migrations + DDL
  manuel + `php artisan migrate:status` pour vérifier l'état.

### Étape 2 - postgresql.conf tuné

Créez `infra/postgres/postgresql.conf` avec les valeurs cibles
(1 GB / 3 GB / 16 MB / 256 MB / 16 MB / 15min / 2 GB / 1.1).

Mise à jour `docker-compose.yml` du service postgres :

```yaml
postgres:
  image: postgres:16-alpine
  command:
    - "postgres"
    - "-c"
    - "config_file=/etc/postgresql/postgresql.conf"
  volumes:
    - postgres_data:/var/lib/postgresql/data
    - ./infra/postgres/postgresql.conf:/etc/postgresql/postgresql.conf:ro
```

Vérification post `docker compose up -d` :

```bash
docker compose exec -T -e PGPASSWORD=resonance postgres \
  psql -h localhost -U resonance -d resonance -t -c "
    SELECT name, setting FROM pg_settings
    WHERE name IN ('shared_buffers','work_mem','effective_cache_size','random_page_cost')
    ORDER BY name;
  "
```

Doit afficher (en pages 8 KB) :
- `shared_buffers = 131072` (1 GB)
- `effective_cache_size = 393216` (3 GB)
- `work_mem = 16384` (16 MB)
- `random_page_cost = 1.1`

### Étape 3 - PgBouncer (transaction pooling + SCRAM passthrough)

#### 3.1 - Init script Postgres pour SCRAM passthrough auth_query

Créez `infra/postgres/init/01-pgbouncer-auth.sh` (chmod +x). Ce script
s'exécute **UNE FOIS** lors du premier `initdb` du volume
`postgres_data` (cf. `/docker-entrypoint-initdb.d/`) :

```bash
#!/bin/sh
set -e
PGBOUNCER_PASSWORD="${PGBOUNCER_PASSWORD:?PGBOUNCER_PASSWORD env var required}"

psql -v ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname "$POSTGRES_DB" \
    --set "pgbouncer_password=${PGBOUNCER_PASSWORD}" <<-'EOSQL'
    CREATE ROLE pgbouncer LOGIN PASSWORD :'pgbouncer_password';
    CREATE SCHEMA pgbouncer;
    CREATE FUNCTION pgbouncer.user_lookup(IN i_username TEXT,
        OUT uname TEXT, OUT phash TEXT) RETURNS RECORD AS $$
    BEGIN
        SELECT usename, passwd FROM pg_shadow
        WHERE usename = i_username
        INTO uname, phash;
        RETURN;
    END;
    $$ LANGUAGE plpgsql SECURITY DEFINER;
    REVOKE ALL ON FUNCTION pgbouncer.user_lookup(TEXT) FROM PUBLIC;
    GRANT EXECUTE ON FUNCTION pgbouncer.user_lookup(TEXT) TO pgbouncer;
    GRANT USAGE ON SCHEMA pgbouncer TO pgbouncer;
EOSQL
```

**Pourquoi SECURITY DEFINER** : la fonction lit `pg_shadow` (table
système contenant les hashes SCRAM des rôles). `SECURITY DEFINER`
fait exécuter la fonction en tant que **propriétaire** (POSTGRES_USER
= resonance, qui est superuser au moment du bootstrap). Sans cela,
le rôle pgbouncer n'aurait pas le droit de lire pg_shadow.

#### 3.2 - Config PgBouncer

Créez `infra/pgbouncer/pgbouncer.ini` :

```ini
[databases]
resonance = host=postgres port=5432 dbname=resonance auth_user=pgbouncer

[pgbouncer]
listen_addr = 0.0.0.0
listen_port = 6432
auth_type = scram-sha-256
auth_user = pgbouncer
auth_query = SELECT uname, phash FROM pgbouncer.user_lookup($1)
auth_file = /etc/pgbouncer/userlist.txt
pool_mode = transaction
max_client_conn = 100
default_pool_size = 25
```

Service Compose :

```yaml
pgbouncer:
  image: edoburu/pgbouncer:latest
  environment:
    DB_HOST: postgres
    DB_PORT: 5432
    DB_NAME: resonance
    DB_USER: pgbouncer
    DB_PASSWORD: ${PGBOUNCER_PASSWORD}
    AUTH_TYPE: scram-sha-256
  ports:
    - "${PGBOUNCER_PORT:-6432}:6432"
  volumes:
    - ./infra/pgbouncer/pgbouncer.ini:/etc/pgbouncer/pgbouncer.ini:ro
  depends_on:
    postgres:
      condition: service_healthy
```

Le `userlist.txt` est généré au démarrage par l'entrypoint edoburu
(à partir de `DB_USER` + `DB_PASSWORD`) - **aucun hash committé**
dans le repo. Seul le cleartext `PGBOUNCER_PASSWORD` transite via env.

#### 3.3 - Dualité de connexion Laravel

Le mode transaction pooling de PgBouncer interdit :

- Les prepared statements survivant entre transactions (chaque
  COMMIT/ROLLBACK rend le backend au pool - la prochaine query
  peut tomber sur un backend différent).
- Les sessions persistantes (`SET search_path`, advisory locks,
  `LISTEN/NOTIFY`).

Côté Laravel :

- **`pgsql` (par défaut)** → `pgbouncer:6432` pour le runtime HTTP
  (FPM workers, queue jobs). Force `PDO::ATTR_EMULATE_PREPARES = true`
  pour rester compatible.
- **`pgsql_direct`** → `postgres:5432` (bypass pooler) pour les
  migrations, seeders, dump SQL, restore - tous les workloads qui
  ouvrent des sessions longues ou des prepared statements multi-tx.

```php
// backend/config/database.php
'pgsql' => [
    // ... (DB_HOST=pgbouncer, DB_PORT=6432)
    'options' => extension_loaded('pdo_pgsql') ? [
        PDO::ATTR_EMULATE_PREPARES => true,
    ] : [],
],
'pgsql_direct' => [
    // ... (DB_HOST_DIRECT=postgres, DB_PORT_DIRECT=5432)
],
```

Mise à jour Makefile :

```make
.PHONY: migrate
migrate: ## Lance les migrations (via pgsql_direct, bypass PgBouncer)
	$(COMPOSE) exec -u app backend php artisan migrate --database=pgsql_direct
```

Et les commandes Artisan `resonance:dump-database`,
`resonance:restore-database` lisent désormais `pgsql_direct` en dur.
Le `resonance:seed` fait `DB::setDefaultConnection('pgsql_direct')`
au début pour que le RealisticDatasetSeeder (insert batch 200 k
tickets) ne tombe pas sur un backend rotation pgbouncer.

### Étape 4 - Recherche full-text Laravel

`backend/app/Http/Controllers/Api/V1/EventController.php` —
`@index` :

```php
if ($q = $request->string('q')->toString()) {
    $query->whereRaw(
        "to_tsvector('french', title || ' ' || description) @@ plainto_tsquery('french', ?)",
        [$q],
    );
}
```

**Critique** : l'expression doit être **strictement identique** à
celle de l'index GIN (`title || ' ' || description`, pas
`title || description`). Sinon le planner choisit un Seq Scan sur
events et l'index n'est pas utilisé. Vérification empirique :

```bash
make explain-no-restore   # voir docs/benchmarks/sql-j3-postgres/latest.txt
```

Doit afficher `Bitmap Index Scan on idx_events_search` pour la
requête (a). Si vous voyez `Seq Scan on events` malgré la migration
appliquée, vérifiez l'expression caractère par caractère.

### Étape 5 - Outillage EXPLAIN

`load-tests/sql/explain-suite.sql` : 5 requêtes représentatives
encadrées par `EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)`. Résolution
dynamique de l'event star + organizer démo via `\gset` (psql) - pas
d'ID hardcodé, robuste aux changements de seed.

```sql
SELECT id AS demo_org_id FROM organizers o
WHERE o.user_id = (SELECT id FROM users WHERE email='organizer@demo.test' LIMIT 1)
LIMIT 1
\gset
```

Cibles Make :

- `make explain-no-restore` - itération rapide, pas de reset DB.
- `make explain` - `make restore` + `explain-no-restore` (cible
  reproductible, calquée sur `make k6` / `make lighthouse`).

Sortie : `docs/benchmarks/sql-j3-postgres/run-<TS>.txt` + alias
`latest.txt`. Le sous-dossier `manual/` accueille des captures de
requêtes ad hoc (cf. `docs/benchmarks/README.md` §3 procédure
manuelle).

### Étape 6 - Régénération du dump SQL

Après migration appliquée :

```bash
make dump   # via pgsql_direct, --schema=public (exclut pgbouncer)
```

Le nouveau `infra/seeds-dump/realistic.sql.gz` (~ 11 MiB) embarque
les 9 indexes - `make restore` repart d'un état canonique optimisé
sans relancer migrate. Le filtre `--schema=public` exclut le
schéma `pgbouncer` (auth_query setup) qui reste géré par l'init
script et survit au DROP SCHEMA public CASCADE du restore.

## 3. Validation

### Smoke tests fonctionnels

```bash
docker compose down -v                       # wipe volumes
docker compose up -d                         # init script PgBouncer
docker compose logs postgres | grep "Resonance"
# → "Resonance - PgBouncer auth role + schema + user_lookup() installés."

make restore                                  # dataset canonique
make migrate                                  # applique les indexes

# Vérifications SQL
docker compose exec postgres psql -U resonance -d resonance -c "
  SELECT count(*) FROM pg_indexes WHERE indexname LIKE 'idx_%';
" # → 10

# Vérifications API via PgBouncer
curl http://localhost:${NGINX_PORT}/api/v1/events?q=sapiente | jq .data | head
# → résultats full-text en < 50 ms

# Vérifications stats organizer
TOKEN=$(curl -s -X POST http://localhost:${NGINX_PORT}/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"organizer@demo.test","password":"password"}' \
  | jq -r .data.token)
curl http://localhost:${NGINX_PORT}/api/v1/organizer/stats \
  -H "Authorization: Bearer $TOKEN"
# → {"data":{"today_orders":0,"month_revenue_cents":...,"fill_rate":0.11,"active_events":60}}

# Vérification PgBouncer pooling sous charge k6
docker compose exec postgres psql -U resonance -d resonance -c "
  SELECT count(*) AS active_connections FROM pg_stat_activity
  WHERE datname='resonance';
"
# → ~ 7 connexions actives (vs 4-20 sans pgbouncer)
```

### Mesures

```bash
make explain     # EXPLAIN suite : doit afficher Bitmap Index Scan
                 # pour (a) full-text et Index Scan pour (b),(d),(e)
make k6          # 3 scénarios → docs/benchmarks/k6-latest/
make lighthouse  # 2 URLs publiques → docs/benchmarks/lighthouse-latest/
```

Comparer aux baselines starter dans `docs/benchmarks/k6-starter/`
et `docs/benchmarks/lhci-starter/`. Documentation différentielle
dans `docs/benchmarks/j3-postgres-comparison.md`.

## 4. Pièges rencontrés

### 4.1 - L'expression GIN doit être identique à la requête

L'index GIN sur une expression (`to_tsvector(...)`) n'est utilisé
par le planner que si la requête utilise **strictement** la même
expression. Différences invisibles qui cassent l'index :

- `title || ' ' || description` (espace) ≠ `title || description`
- `to_tsvector('french', ...)` ≠ `to_tsvector('simple', ...)`
- `to_tsvector(...)` (sans config) → utilise `default_text_search_config`
  qui peut varier entre sessions.

**Mitigation** : on a fixé `default_text_search_config = 'pg_catalog.french'`
dans `postgresql.conf` ET on passe `'french'` explicitement dans la
requête + l'index.

### 4.2 - Migrations Laravel et CREATE INDEX CONCURRENTLY

Les migrations Laravel s'exécutent dans une transaction par défaut.
`CREATE INDEX CONCURRENTLY` exige d'être **hors** transaction (sinon
erreur `CREATE INDEX CONCURRENTLY cannot run inside a transaction
block`). En training, on utilise `CREATE INDEX` bloquant (~ secondes
sur tickets 200 k). En prod live, voir §"Note production" ci-dessus.

### 4.3 - pgbouncer + prepared statements Laravel

PDO Postgres utilise par défaut des prepared statements **réels**
(server-side) qui survivent à la transaction. En transaction pooling
PgBouncer, le backend change entre transactions → la prochaine
requête trouve la prepared statement absente → erreur `prepared
statement "..." does not exist`.

**Mitigation** : `PDO::ATTR_EMULATE_PREPARES = true` sur la connexion
`pgsql` (cf. config/database.php). Les prepared sont émulées côté
PHP (substitution de variables avant envoi), aucune trace côté
backend.

### 4.4 - Migrations + seeders → besoin de `pgsql_direct`

Une fois `DB_HOST=pgbouncer DB_PORT=6432`, `php artisan migrate`
échoue silencieusement ou avec des erreurs erratiques (DDL longues,
prepared statements). Il faut explicitement `--database=pgsql_direct`
sur migrate / seed / rollback. Le `Makefile` l'a baked-in. Si vous
appellez `php artisan` directement, n'oubliez pas le flag.

### 4.5 - Dump SQL et schéma pgbouncer

`pg_dump` sans filtre dumpe **toutes** les schémas, y compris
`pgbouncer`. Le restore ferait alors `DROP SCHEMA public` puis
tenterait de recréer le schéma `pgbouncer` (qui existe déjà depuis
l'init script). Conflit possible.

**Mitigation** : `pg_dump --schema=public` filtre sur le schéma
applicatif uniquement. Le schéma `pgbouncer` reste géré par l'init
script et survit aux DROP SCHEMA public CASCADE. Cf.
`ResonanceDumpDatabaseCommand`.

### 4.6 - Init script ne ré-exécute pas après le premier initdb

Les scripts dans `/docker-entrypoint-initdb.d/` ne s'exécutent que
**lors du premier initdb du volume**. Si vous modifiez l'init script
après coup, il faut `docker compose down -v && docker compose up -d`
pour le ré-exécuter (volume recréé).

Pour les environnements existants (volume déjà initialisé), vous
pouvez exécuter manuellement le SQL :

```bash
docker compose exec postgres psql -U postgres -d resonance \
  -v "pgbouncer_password=$PGBOUNCER_PASSWORD" \
  -f /docker-entrypoint-initdb.d/01-pgbouncer-auth.sh
```

(ou copier le contenu SQL et l'exécuter à la main).

## 5. Références

- `docs/benchmarks/j3-postgres-comparison.md` - comparaison
  starter ↔ j3-postgres complète (SQL + k6 + Lighthouse).
- `docs/benchmarks/sql-j3-postgres/README.md` - captures EXPLAIN
  before/after avec lecture des plans.
- `infra/postgres/postgresql.conf` - config tunée + commentaires.
- `infra/postgres/init/01-pgbouncer-auth.sh` - bootstrap auth_query.
- `infra/pgbouncer/pgbouncer.ini` - config pooler.
- `backend/database/migrations/2026_05_07_140100_add_performance_indexes.php`
  - les 9 indexes commentés.

Documentation Postgres :

- [GIN indexes for full-text](https://www.postgresql.org/docs/16/textsearch-indexes.html)
- [tsvector / tsquery](https://www.postgresql.org/docs/16/datatype-textsearch.html)
- [Partial indexes](https://www.postgresql.org/docs/16/indexes-partial.html)
- [postgresql.conf tuning](https://wiki.postgresql.org/wiki/Tuning_Your_PostgreSQL_Server)

Documentation PgBouncer :

- [Authentication](https://www.pgbouncer.org/config.html#authentication-settings)
- [Pool modes](https://www.pgbouncer.org/config.html#pool_mode)
- [edoburu/pgbouncer](https://github.com/edoburu/docker-pgbouncer)
