# Atelier J3 - Laravel : eager loading, cache, queues, Octane

> **Branche solution** : `solution/j3-laravel`
> **Pré-requis** : avoir suivi les ateliers précédents OU connaître le
> contenu de `docs/architecture.md`.

> **Note méthodologique - cibles différentielles vs absolues**
> (cf. `docs/architecture.md` §6
> "Cibles différentielles vs cibles absolues").
>
> Cet atelier livre des **gains différentiels** - l'apport propre de
> J3-laravel mesuré contre le starter, périmètre Laravel applicatif +
> runtime backend isolé. Les **cibles absolues** (LCP home < 1.5 s,
> score Performance home ≥ 0.90, etc.) supposent que **toutes** les
> optimisations sont composées sur la branche `final`. Cas d'école sur
> cette branche : le **TTFB API s'effondre** (135 ms → 6 ms p95 sous
> Octane + cache Redis) mais le **LCP home reste à 10.4 s** parce que
> le LCP est dominé par l'image hero JPEG full-size sans CDN - c'est
> exactement le scope de `solution/j2-bundle` (AVIF) +
> `solution/j1-cdn-cache` (Cache-Control immutable). C'est ce que la
> note méthodologique veut éviter de prendre pour un échec de J3 :
> c'est la signature attendue d'optimisations qui se composent.

### Ce que cet atelier améliore (gains différentiels mesurés)

Mesures vs `main` (cf. `docs/benchmarks/j3-laravel-comparison.md`,
mêmes conditions throttling Mobile / Slow 4G / 4× CPU pour
Lighthouse, mêmes profils 20-30 VUs pour k6) :

#### Backend (k6 - TTFB / durée serveur)

| Métrique                            | Starter    | J3-laravel | Δ              |
|-------------------------------------|-----------:|-----------:|---------------:|
| `/api/v1/events` p95 (search)       |   2.9 s    |  **6.3 ms**|   **−99.8 %**  |
| `/api/v1/events` médian             |  135 ms    |   3.1 ms   |   **−98 %**    |
| Home SSR p95 (20 VUs)               |   5.3 s    | **19.9 ms**|   **−99.6 %**  |
| Home SSR médian                     |   4.4 s    |  14.2 ms   |   **−99.7 %**  |
| Tunnel achat médian succès 201      |   1.39 s   |   1.17 s   |   −16 %        |
| Tunnel achat throughput             |  ~ 94/s    |  ~ 644/s   |   **×6.8**     |
| Requêtes SQL events/index           |       49   |        **3**|  **÷ 16**      |

#### Frontend (Lighthouse - Core Web Vitals)

| Métrique                            | Starter   | J3-laravel | Δ            |
|-------------------------------------|----------:|-----------:|-------------:|
| Lighthouse Performance home         |    0.55   |  **0.68**  |  **+24 %**   |
| **TTFB home** (Lighthouse)          |    2.3 s  | **18 ms**  |  **−99 %**   |
| **FCP home**                        |   15.9 s  |  **3.3 s** |  **−79 %**   |
| LCP home                            |   16.3 s  |  10.4 s    |   −36 %      |

L'atelier valide **6 leviers** : eager loading + Resources `whenLoaded`,
cache Redis applicatif avec tags, queues Redis + Horizon (PDF + mail
asynchrones), pagination cursor sur `/events`, runtime Octane +
FrankenPHP, OPcache + JIT + `php artisan optimize`.

### Ce qui n'est PAS dans le périmètre

Les cibles suivantes restent **non atteignables** sur cette branche
isolée et seront couvertes en branche `final` :

- **LCP home < 1.5 s** : reste à 10.4 s parce que le LCP est dominé
  par l'image hero JPEG full-size (1920×1080, ~ 290 Ko) sans CDN.
  Couvert par `solution/j2-bundle` (image AVIF + IPX) +
  `solution/j1-cdn-cache` (Cache-Control immutable sur `_nuxt/*` et
  `media/*`).
- **LCP fiche < 1.5 s** : même cause, même résolution composée.
- **Score Performance home ≥ 0.90** : reste à 0.68. Avec j1 + j2
  composés, le score atteindra la cible.
- **k6 checkout `failed_rate` < 5 %** : non atteignable car le
  bottleneck est l'absence de verrou Postgres
  (`SELECT FOR UPDATE SKIP LOCKED`) sur `ticket_categories.sold`.
  Hors périmètre j3-laravel (à reprendre dans une itération
  concurrence dédiée).
- **TTFB API en cas de cache MISS sur ILIKE long-tail** : reste
  à ~ 80-150 ms parce que `LIKE '%terme%'` sur `title || description`
  est en seq scan. Cible `solution/j3-postgres` (gin tsvector FR).

Note importante sur le `failed_rate` : cette branche **dégrade
apparemment** le `failed_rate` k6 checkout (90.8 % → 98.66 %). Ce
n'est pas une régression. Octane absorbe **7× plus de requêtes par
seconde** (~ 644/s vs ~ 94/s starter), donc le quota fixe (~ 904
places) se vide proportionnellement plus vite sous le même plateau
de 20 VUs. Le **nombre absolu de succès est identique** au starter
(~ 904 orders 201). C'est l'absence de verrou qui plafonne, pas
J3-laravel qui régresse.

Ces gains additionnels arrivent par composition lors du merge des
solutions sur la branche `final`.

## 1. Objectif pédagogique

Réduire le **TTFB API**, le **temps de bootstrap Laravel** et le
**coût HTTP du tunnel d'achat** sans toucher au frontend ni à la
base, qui sont l'objet d'autres branches solution. Six leviers
complémentaires :

1. **Eager loading + Resources `whenLoaded()`** : éliminer les N+1
   dans les chemins lecture (Event, Order, Ticket, Participant).
   Cible : `/events` index passe de 49 à 3 requêtes SQL.
2. **Cache Redis avec tags** : `Cache::tags(['events'])->remember(...)`
   sur les hot paths publics. TTL 60 s liste, 300 s fiche, 30 s
   stats organizer. Tag-flush sur les writes organizer.
3. **Queues Redis + supervisor Horizon** : déporter la génération
   PDF dompdf et l'envoi SMTP de confirmation hors du thread HTTP.
   Le mock paiement reste sync (bottleneck métier).
4. **Pagination cursor sur `/api/v1/events`** : payload divisé par
   ~ 50 (4 Mo → 80 Ko), allège le SSR Nuxt et rend le cache Redis
   viable (clé bornée par `cursor` + filtres).
5. **Octane + FrankenPHP** : remplacer PHP-FPM (FastCGI) par un
   runtime à workers persistants. Plus de bootstrap Laravel à chaque
   requête.
6. **OPcache + JIT tracing 64 Mo + `php artisan optimize`** :
   bytecode compilé une fois, hot paths compilés en code natif au
   fil de l'exécution, config/route/view/event:cache pré-compilés.

Résultat composé mesuré (cf.
`docs/benchmarks/j3-laravel-comparison.md`) :

- **k6 search-load p95** : 2.9 s → **6.3 ms** (−99.8 %).
- **k6 home p95 20 VUs** : 5.3 s → **19.9 ms** (−99.6 %).
- **Lighthouse TTFB home** : 2.3 s → **18 ms** (−99 %).
- **Lighthouse FCP home** : 15.9 s → **3.3 s** (−79 %).
- **Performance home** : 0.55 → **0.68** (+24 %).

## 2. Énoncé pas-à-pas (depuis `main`)

```bash
git checkout main
git pull
git checkout -b mon-atelier-j3-laravel
```

### Étape 1 - Eager loading + Resources `whenLoaded()`

Dans **toutes** les Resources qui exposent des relations, basculer
en `whenLoaded()` :

```php
// EventResource
'organizer' => new OrganizerResource($this->whenLoaded('organizer')),
'media' => MediaResource::collection($this->whenLoaded('media')),
'sessions' => EventSessionResource::collection($this->whenLoaded('sessions')),

// EventSessionResource
'ticket_categories' => TicketCategoryResource::collection(
    $this->whenLoaded('ticketCategories')
),

// OrderResource, TicketResource, ParticipantResource : idem.
```

Côté caller, poser le `with([...])` adéquat :

```php
// EventController@index : 49 → 3 requêtes
$query = Event::query()
    ->with([
        'organizer',
        'media' => fn ($q) => $q->orderBy('position'),
    ])
    ->where('status', Event::STATUS_PUBLISHED);

// EventController@show : avec sessions + categories
$event = Event::with([
    'organizer',
    'media' => fn ($q) => $q->orderBy('position'),
    'sessions.ticketCategories',
])->where('slug', $slug)->firstOrFail();

// Organizer/ParticipantsController : avec order.user + category
$query = Ticket::with(['order.user', 'ticketCategory'])
    ->whereIn('event_session_id', $sessionIds)
    ->where('status', Ticket::STATUS_VALID);
```

**Vérification** : `DB::enableQueryLog()` puis appeler
`EventController@index` → compter `count(DB::getQueryLog())`. On
doit passer de ~ 49 à 3.

### Étape 2 - Cache Redis applicatif avec tags

Switch `CACHE_STORE=redis` dans `.env`. Le `config/database.php` a
déjà une connexion Redis `cache` configurée sur la **db 1** (séparée
de la db 0 utilisée plus tard pour les queues).

```php
// EventController@index
$payload = Cache::tags(['events'])->remember(
    'events:index:'.md5(serialize($filters).'|cursor='.$cursor),
    60,
    fn () => [
        'data' => EventResource::collection($paginator->items())->resolve(),
        'meta' => [...],
    ]
);
```

**Important** : on cache la **réponse JSON** (array sérialisé), pas
l'objet `CursorPaginator`. Laravel 11+ pose `cache.serializable_classes
= false` par défaut (prévention gadget chain), ce qui rejetterait les
classes Eloquent et Paginator au reload (`__PHP_Incomplete_Class`).
Même contrainte sur `EventController@show`, `@sessions` et
`StatsController@salesChart` (où `Collection<stdClass>` doit être cast
en `array`).

**Invalidation** : depuis les writes organizer
(`Organizer/EventController@store/update/destroy`), tag-flush :

```php
Cache::tags(['events'])->flush();
Cache::tags(["organizer:{$event->organizer_id}"])->flush();
```

Le tag-flush plutôt que key-by-key : la liste des clés possibles
(filtres `q/city/category/from` × cursors) est non bornée. Le tag
permet une invalidation O(1) côté Redis.

### Étape 3 - Queues Redis + Horizon

```bash
docker compose exec -u app backend composer require laravel/horizon
docker compose exec -u app backend php artisan horizon:install
```

Switch `QUEUE_CONNECTION=redis` dans `.env`. Crée 2 jobs ShouldQueue :

```php
// app/Jobs/GenerateTicketPdfJob.php
public function handle(TicketPdfService $pdf): void
{
    $pdf->generate($this->ticket);
}

// app/Jobs/SendOrderConfirmationEmailJob.php
public function handle(): void
{
    Mail::to($this->email)->send(new OrderConfirmationMail($this->order));
}
```

Modifiez `OrderController@store` pour dispatcher au lieu d'appeler
synchrone :

```php
foreach ($order->tickets as $ticket) {
    GenerateTicketPdfJob::dispatch($ticket);
}
SendOrderConfirmationEmailJob::dispatch($order, $user->email);
```

Ajoute le supervisor Horizon dans `docker-compose.yml` :

```yaml
horizon:
  image: resonance/backend:dev
  command: ["php", "artisan", "horizon"]
  user: "${UID:-1000}:${GID:-1000}"
  depends_on:
    backend: { condition: service_started }
    redis: { condition: service_healthy }
  # ...
```

Exposez `/horizon` via Nginx → backend (Gate ouvert en local par
défaut, cf. `Laravel\Horizon\Http\Middleware\Authenticate`). Horizon
5.46 inline ses assets (data URI), pas besoin de location séparée
pour `/vendor/horizon/*`.

### Étape 4 - Pagination cursor sur `/api/v1/events`

```php
// EventController@index
$paginator = $query->orderBy('published_at', 'desc')
    ->orderBy('id', 'desc')
    ->cursorPaginate(20);

return response()->json([
    'data' => EventResource::collection($paginator->items()),
    'meta' => [
        'next_cursor' => $paginator->nextCursor()?->encode(),
        'prev_cursor' => $paginator->previousCursor()?->encode(),
        'per_page' => $paginator->perPage(),
    ],
]);
```

Côté frontend :

- **Home** (`pages/index.vue`) : type `CursorPaginatedResponse<Event>`
  + slice sur 20 items (hero + 8 "cette semaine" + 11 "populaires").
- **Search** (`pages/events/index.vue`) : bouton "Charger plus" qui
  consomme `meta.next_cursor` ; reset des résultats sur change de
  filtres.

#### Quand paginer ? - Critère de décision

**On pagine `/api/v1/events` parce que** cet endpoint retournait 4 Mo
(1 500 events) et plombait le LCP de la home (le SSR Nuxt attend la
réponse complète avant de rendre).

**On ne pagine PAS dans cette branche** :

- **`/me/tickets`** : volume typique < 30 tickets par utilisateur,
  pas de douleur réelle.
- **`/organizer/events`** : volume typique < 100 events par
  organizer, douleur marginale.
- **`/organizer/events/{id}/participants`** : douleur réelle (5 000+
  tickets pour les events stars) mais résolue côté front par
  virtualisation (`solution/j2-dashboard` - `vue-virtual-scroller`)
  et côté DB par index (`solution/j3-postgres`). La virtualisation
  composée à la pagination cursor donnerait le résultat optimal en
  branche `final`, mais on reste minimaliste ici pour ne pas recouper
  d'autres branches.

**Critère général** : paginer quand le volume devient suffisant pour
saturer le réseau ou bloquer le rendu. Pas comme bonne hygiène
universelle. La pagination a un coût (front à adapter, méta-données
à exposer, état de cursor à gérer côté client) - l'introduire sans
douleur mesurable est un anti-pattern.

### Étape 5 - Octane + FrankenPHP

```bash
docker compose exec -u app backend composer require laravel/octane
docker compose exec -u app backend php artisan octane:install --server=frankenphp
```

Réécrivez `infra/docker/backend.Dockerfile` sur l'image
`dunglas/frankenphp:latest-php8.3` (Debian 12 slim, PHP 8.3,
frankenphp embarqué). Installez les extensions Laravel via
`install-php-extensions` :

```dockerfile
FROM dunglas/frankenphp:latest-php8.3 AS base

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        bash git curl unzip gzip postgresql-client \
        libzip-dev libpng-dev libjpeg-dev libfreetype-dev \
        libicu-dev libpq-dev libonig-dev; \
    install-php-extensions \
        pdo_pgsql redis intl gd zip pcntl bcmath opcache; \
    rm -rf /var/lib/apt/lists/*

# ...

CMD ["sh", "-c", "php artisan optimize && exec php artisan octane:start \
     --server=frankenphp --host=0.0.0.0 --port=8000 \
     --workers=auto --max-requests=500"]
```

Modifiez `docker-compose.yml` backend : `expose: "8000"` au lieu de
`"9000"`.

Modifiez `infra/nginx/sites/resonance.conf` : `fastcgi_pass` →
`proxy_pass http://backend:8000` pour `/api/` et `/horizon`.
Ajoutez un upstream avec `keepalive` pour économiser le 3-way
handshake :

```nginx
upstream backend_octane {
    server backend:8000;
    keepalive 32;
}

location /api/ {
    proxy_pass http://backend_octane;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    # ...
}
```

#### Sessions et Octane - pourquoi pas Redis ?

Notre starter utilise `SESSION_DRIVER=database`. Pourquoi ne pas
basculer en Redis dans cette branche, puisque Redis est en place
pour le cache et les queues ?

**Parce qu'il n'y a aucune douleur mesurable.** Les sessions DB sur
Postgres avec PK index sont rapides (< 1 ms par requête), Octane
n'introduit pas de problème particulier sur les sessions DB, et
changer de driver ajouterait du périmètre sans gain chiffrable.

**Optimiser ce qui n'est pas mesurable est un anti-pattern de la
performance.** En production réelle, le choix Redis vs DB pour les
sessions dépend des contraintes : volume de connexions concurrentes,
coût d'une lecture session sur le path critique, durée de vie
souhaitée. Pour Resonance, la DB suffit largement.

#### Points de vigilance Octane

- **État partagé entre requêtes** : Octane garde l'application en
  mémoire. Tout singleton applicatif qui mute son état est dangereux
  (fuite entre utilisateurs). Vérifier qu'aucun service injecté ne
  garde de référence à un Request, à un User, à un Auth, etc.
- **`--max-requests=500`** : recycle le worker régulièrement, aide à
  débusquer les fuites mémoire applicatives sans attendre un crash en
  prod.
- **Seeders Artisan** : `resonance:seed`, `resonance:dump-database`,
  `resonance:restore-database` continuent de fonctionner via
  `docker compose exec -u app backend php artisan ...` - ce sont des
  process séparés du worker Octane, pas de souci.
- **Premier démarrage** : la version frankenphp embarquée dans
  `dunglas/frankenphp` (1.1.5) est jugée incompatible par Octane qui
  télécharge automatiquement une version compatible dans
  `/app/frankenphp` (~ 50 Mo, ~ 5 s, persistant via le bind-mount).
  Les démarrages suivants sont instantanés.

### Étape 6 - OPcache + JIT + `php artisan optimize`

Dans le Dockerfile, pose `/usr/local/etc/php/conf.d/zz-resonance.ini` :

```ini
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.jit=tracing
opcache.jit_buffer_size=64M
```

`validate_timestamps=1` garde le confort dev (modifs source
détectées toutes les 2 s) - en prod on poserait `=0` + déploiement
atomique avec `opcache_reset()`.

`enable_cli=1` permet aux workers Horizon d'en bénéficier aussi.

Le CMD du Dockerfile chaîne `php artisan optimize` avant
`octane:start` :

```dockerfile
CMD ["sh", "-c", "php artisan optimize && exec php artisan octane:start ..."]
```

`optimize` chaîne `config:cache`, `route:cache`, `view:cache`,
`event:cache` - tous idempotents, écrits dans `bootstrap/cache/` qui
persiste via le bind-mount. Cette pré-compilation libère le worker
Octane des coûts de bootstrap au cold start (~ 100-200 ms).

## 3. Vérifications

```bash
# Rebuild + restart la stack
make frontend-rebuild  # si frontend modifié (pagination)
docker compose build backend
docker compose up -d --force-recreate

# 1. Compter les requêtes SQL sur /events index
docker compose exec -u app backend bash -c '
  XDG_CONFIG_HOME=/tmp php artisan tinker --execute="
    \DB::enableQueryLog();
    \Cache::store(\"redis\")->flush();
    (new \App\Http\Controllers\Api\V1\EventController())->index(new \Illuminate\Http\Request());
    echo \"queries=\" . count(\DB::getQueryLog());
  "
'
# attendu : queries=3

# 2. Mesurer le TTFB API (cache HIT)
curl -s -o /dev/null http://localhost:8081/api/v1/events  # warm-up
for i in 1 2 3; do
  curl -s -o /dev/null -w "  hit$i: %{time_total}s\n" http://localhost:8081/api/v1/events
done
# attendu : ~ 4-10 ms par hit

# 3. Chronométrer le checkout
TOKEN=$(curl -s -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"visitor@demo.test","password":"password"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['token'])")

START=$(date +%s.%N)
curl -s -X POST http://localhost:8081/api/v1/orders \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"event_session_id":600,"items":[{"ticket_category_id":1798,"quantity":1,"holder_name":"Test"}]}' \
  -o /dev/null -w "  http=%{http_code}\n"
END=$(date +%s.%N)
echo "  dur=$(echo "$END - $START" | bc)s"
# attendu : http=201, dur=~ 1.0-1.5 s (mock paiement préservé)

# 4. Vérifier Mailpit reçoit le mail asynchrone
curl -s "http://localhost:8025/api/v1/messages?limit=2" | head
# attendu : "Confirmation de commande #XXXXX -> visitor@demo.test"

# 5. Horizon dashboard
open http://localhost:8081/horizon
# attendu : SPA charge, jobs/min > 0 si tu viens de checkout

# 6. Benchmarks complets
make k6           # → docs/benchmarks/k6-latest/
make lighthouse   # → .lighthouseci/
```

## 4. Erreurs typiques

- **`__PHP_Incomplete_Class` au reload du cache Redis** : tu caches
  un objet Eloquent ou Paginator. Cache la version *sérialisée* (array
  via `Resource::resolve()`). Cf. `cache.serializable_classes` dans
  `config/cache.php` (false par défaut sur Laravel 11+).
- **`Class "Predis\Client" not found`** : `REDIS_CLIENT=phpredis` mais
  l'extension PHP `redis` n'est pas installée. Ajoute
  `install-php-extensions redis` au Dockerfile.
- **`fastcgi_pass` invoque toujours FPM mort** : tu as oublié de
  basculer Nginx en `proxy_pass http://backend:8000`. Vérifie
  `infra/nginx/sites/resonance.conf` ET `infra/nginx/nginx.conf` (pas
  d'upstream `backend_fpm` orphelin).
- **Job dispatch fait rien** : `QUEUE_CONNECTION=sync` dans `.env`.
  Bascule en `redis`. Vérifie aussi que `php artisan horizon:status`
  affiche `Horizon is running.`.
- **PDF jamais généré** : worker Horizon down. `docker compose logs
  horizon` doit montrer `INFO Horizon started successfully.`. Si non,
  vérifier que l'image backend a bien été reconstruite après
  `composer require laravel/horizon`.
- **Octane ne trouve pas frankenphp** : au premier démarrage, Octane
  télécharge frankenphp dans `/app/frankenphp` (~ 50 Mo, ~ 5 s). Les
  démarrages suivants utilisent ce binaire local. Si tu veux purger,
  `rm backend/frankenphp` puis `make up`.

## 5. Gain composé attendu sur `final`

Quand `solution/j3-laravel` mergera avec `solution/j1-cdn-cache`,
`solution/j2-bundle`, `solution/j2-dashboard` et
`solution/j3-postgres`, les gains se composent :

- **TTFB API** : ~ 0 ms (J1 ajoute SWR Nitro côté frontend en plus du
  cache Redis backend J3).
- **LCP home** : passe sous 1.5 s (J1 efface le TTFB SSR + J2 sert
  l'image hero en AVIF + J3 fait que `/api/v1/events` répond en 6 ms).
- **k6 search p95** : reste à quelques ms (J3-postgres ajoute l'index
  gin tsvector qui résout le long-tail ILIKE).
- **k6 checkout `failed_rate`** : le verrou `SELECT FOR UPDATE SKIP
  LOCKED` (itération concurrence dédiée) résout l'épuisement quota
  sous concurrence.

## 6. Marqueurs `@perf-debt` résolus par cette branche

```text
backend/app/Http/Controllers/Api/V1/EventController.php
  - N+1 organizer/media/sessions
  - pas de Cache::remember sur la home
  - pas de pagination

backend/app/Http/Controllers/Api/V1/OrderController.php
  - dompdf inline 200-500 ms × N tickets
  - SMTP synchrone bloquant

backend/app/Http/Controllers/Api/V1/Organizer/EventController.php
  - N+1 sessions/media

backend/app/Http/Controllers/Api/V1/Organizer/StatsController.php
  - aucun cache (Cache::remember) sur les KPIs polling 10 s

backend/app/Http/Controllers/Api/V1/Organizer/ParticipantsController.php
  - N+1 order.user + ticketCategory

backend/app/Http/Controllers/Api/V1/TicketController.php
  - N+1 ticketCategory + eventSession.event

backend/app/Http/Resources/{Event,EventSession,Order,Ticket,Participant}Resource.php
  - relations chargées sans whenLoaded()

backend/app/Mail/OrderConfirmationMail.php
  - envoi SMTP bloquant

backend/app/Services/TicketPdfService.php
  - dompdf synchrone bloquant le thread HTTP

infra/docker/backend.Dockerfile
  - opcache désactivé / pas de JIT / pas d'Octane

infra/nginx/sites/resonance.conf
  - pas de keepalive / fastcgi_keep_conn (résolu via passage en proxy
    HTTP avec keepalive 32 sur l'upstream)
```

Marqueurs **non résolus** par cette branche (par périmètre) :

```text
backend/database/migrations/*  → solution/j3-postgres (index)
backend/app/Http/Controllers/Api/V1/OrderController.php
  - SELECT FOR UPDATE SKIP LOCKED  → itération concurrence dédiée
backend/app/Http/Controllers/Api/V1/EventController.php
  - ILIKE sans index FTS  → solution/j3-postgres
infra/nginx/{nginx.conf,sites/resonance.conf}
  - HTTP/1.1, gzip OFF, brotli OFF, pas de Cache-Control
    → solution/j1-cdn-cache
```
