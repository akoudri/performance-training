# Atelier J1 - Caching et délivrance HTTP

> **Branche solution** : `solution/j1-cdn-cache`

> **Note méthodologique - cibles différentielles vs absolues**
> (cf. `docs/architecture.md` §6
> "Cibles différentielles vs cibles absolues").
>
> Cet atelier livre des **gains différentiels** - l'apport propre de
> J1 mesuré contre le starter, périmètre cache HTTP + délivrance
> Nginx isolé. Les **cibles absolues** (LCP home < 1.5 s, score
> Performance home ≥ 0.90, etc.) supposent que **toutes** les
> optimisations sont composées sur la branche `final`. Comparer
> cette branche isolée à la cible finale est un faux négatif.

### Ce que cet atelier améliore (gains différentiels mesurés)

Mesures vs `main` (cf. `docs/benchmarks/j1-cdn-cache-comparison.md`) :

| Métrique                       | Starter | J1 cdn-cache | Δ          |
|--------------------------------|--------:|-------------:|-----------:|
| Lighthouse Performance home    |    0.55 |    **0.76**  |  +38 %     |
| Lighthouse LCP home            |  16.3 s |    **5.1 s** |  −69 %     |
| Lighthouse FCP home            |  15.9 s |     3.0 s    |  −81 %     |
| Lighthouse TTFB home           |   2.3 s |    **0 ms** (cache hit)| —|
| k6 homepage-load `req_duration` med | 4.4 s | **1.07 ms** | ÷ 4 100  |
| k6 homepage-load p95           |   5.3 s |    **2.14 ms** | ÷ 2 480  |
| Compression HTML (`/`)         |  20 kB  |  3.4–3.7 kB  |  ÷ 5–6     |
| Lighthouse Performance fiche   |    0.91 |     0.91     |  inchangé  |
| Lighthouse LCP fiche           |   3.0 s |     3.0 s    |  inchangé  |

L'atelier valide **3 leviers** : compression Nginx (gzip + brotli),
Cache-Control immutable sur statiques fingerprintés, SWR Nitro côté
serveur Node + Cache-Control HTTP applicatif (Laravel middleware).

### Ce qui n'est PAS dans le périmètre

Les cibles suivantes restent **non atteignables** sur cette branche
isolée et seront couvertes en branche `final` :

- **LCP home < 1.5 s** : J1 le fait passer de 16.3 s à 5.1 s grâce au
  cache Nitro qui efface le TTFB (2.3 s → 0 ms après le 1ᵉʳ hit chaud).
  Ce qui reste à 5.1 s est dominé par le **poids du LCP element**
  (image hero JPEG full-size ~ 290 Ko sur Slow 4G ≈ 1.5 s download)
  + le **FCP** (les chunks Nuxt restent identiques en taille). Pour
  passer sous 1.5 s, il faut aussi `solution/j2-bundle` (image AVIF
  ~ 169 Ko, polices self-hostées qui débloquent FCP).
- **LCP fiche < 1.5 s** : reste à 3.0 s parce que la fiche n'a aucune
  pathologie cache ; J1 ne touche pas au poids de l'image. Cible
  atteignable seulement avec j2-bundle (AVIF) + j3-laravel (cache
  applicatif côté API).
- **TTFB API `/api/v1/events` p95 < 200 ms** : reste à 2.36 s
  (slightly improved depuis 2.9 s starter) parce que J1 ne pose que
  des **headers** Cache-Control sur l'API ; le SQL `ILIKE` non indexé
  reste l'objet de `solution/j3-postgres`.
- **Tunnel achat médian < 500 ms** : reste à 1.29 s parce que J1 ne
  touche pas au backend (pas de queues Redis, pas de SELECT FOR
  UPDATE SKIP LOCKED). Cible atteignable seulement avec
  `solution/j3-laravel`.

Ces gains additionnels arriveront par composition lors du merge des
solutions sur la branche `final`.

## 1. Objectif pédagogique

Faire chuter le **TTFB**, le **LCP** et le **payload réseau** de la home
sans toucher au code applicatif. Trois leviers complémentaires :

1. **Compression HTTP** au niveau Nginx (gzip + brotli) - réduit le
   payload sur le wire.
2. **Cache HTTP browser** : `Cache-Control` immutable sur les chunks
   Vite fingerprintés (`/_nuxt/*`) ; SWR sur `/media/*` ; max-age + SWR
   applicatif sur les endpoints API publics (`Cache-Control` posé par
   middleware Laravel).
3. **Cache applicatif serveur** : `routeRules SWR` Nuxt - Nitro stocke
   la HTML rendue en mémoire côté serveur Node, ce qui transforme
   radicalement la médiane k6 sur la home.

Cible chiffrée : Performance Lighthouse home **0.55 → > 0.75**, LCP
**16.3 s → < 6 s**, k6 home p95 **5.3 s → < 2 s**.

## 2. Énoncé pas-à-pas (depuis `main`)

```bash
git checkout main
git pull
git checkout -b mon-atelier-j1-cdn-cache
```

### Étape 1 - Activer la compression Nginx

Éditez `infra/nginx/nginx.conf`. Cherchez les marqueurs
`@perf-debt: gzip OFF` et `@perf-debt: brotli OFF`. Activez :

```nginx
gzip on;
gzip_vary on;
gzip_proxied any;
gzip_comp_level 6;
gzip_min_length 1024;
gzip_types
    text/plain text/css text/xml
    application/javascript application/x-javascript application/json
    application/xml application/xml+rss
    image/svg+xml font/woff2;
gzip_static on;

brotli on;
brotli_static on;
brotli_comp_level 6;
brotli_types
    text/plain text/css text/xml
    application/javascript application/x-javascript application/json
    application/xml application/xml+rss
    image/svg+xml font/woff2;
```

> Le module brotli est déjà compilé dans l'image `fholzer/nginx-brotli`,
> il suffit de l'activer. `text/html` est compressé implicitement quand
> `gzip on;` est posé (pas besoin de l'inclure dans `gzip_types`).

### Étape 2 - Activer HTTP/2 + Cache-Control statiques

Éditez `infra/nginx/sites/resonance.conf`. Activez HTTP/2 (h2c, sans TLS —
TLS sort du périmètre J1) :

```nginx
listen 80 default_server;
listen [::]:80 default_server;
http2 on;       # syntaxe Nginx 1.25+
```

Posez un `Cache-Control` immutable sur les assets Vite fingerprintés :

```nginx
location /_nuxt/ {
    proxy_pass http://nuxt_node;
    # ... (reste des proxy_set_header inchangés)
    add_header Cache-Control "public, max-age=31536000, immutable" always;
}

location /media/ {
    proxy_pass http://nuxt_node;
    # ... (reste des proxy_set_header inchangés)
    add_header Cache-Control "public, max-age=86400, stale-while-revalidate=604800" always;
}
```

> Pourquoi `immutable` sur `/_nuxt/*` ? Vite fingerprinte les noms de
> chunks (`abc123.js`) → une URL est par construction figée, le browser
> peut la mettre en cache 1 an sans revalidation.

Reloadez Nginx :

```bash
docker compose restart nginx
```

### Étape 3 - Middleware Laravel `SetEventsCacheControl`

Créez `backend/app/Http/Middleware/SetEventsCacheControl.php`. Politique :

- Liste `/api/v1/events` : `public, max-age=60, s-maxage=60, swr=300`
- Fiche / sessions : `public, max-age=300, s-maxage=300, swr=900`

```php
<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetEventsCacheControl
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() || $response->getStatusCode() !== 200) {
            return $response;
        }

        $isList = $request->is('api/v1/events');
        [$maxAge, $swr] = $isList ? [60, 300] : [300, 900];

        $response->headers->set(
            'Cache-Control',
            "public, max-age={$maxAge}, s-maxage={$maxAge}, stale-while-revalidate={$swr}",
        );

        return $response;
    }
}
```

> `isMethodSafe()` couvre GET et HEAD (HEAD doit renvoyer les mêmes
> headers que GET par RFC). Seul le 200 reçoit les headers - on évite
> de polluer une 404 / 500.

Appliquez le middleware dans `backend/routes/api.php` (Laravel 11+ n'a
plus de `RouteServiceProvider`, on injecte le middleware à la définition
des routes) :

```php
use App\Http\Middleware\SetEventsCacheControl;

Route::middleware(SetEventsCacheControl::class)->group(function (): void {
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{slug}', [EventController::class, 'show']);
    Route::get('/events/{slug}/sessions', [EventController::class, 'sessions']);
});
```

> Pas besoin de redémarrer le backend : OPcache est désactivé en
> starter, FPM relit le PHP à chaque requête.

### Étape 4 - `routeRules` SWR Nuxt

Éditez `frontend/nuxt.config.ts`. Ajoutez :

```ts
routeRules: {
  '/': { swr: 60 },
  '/events': { swr: 60 },
  '/events/**': { swr: 300 },
  '/organizer/**': { ssr: false },
},
```

Rebuild + redémarrez le service frontend (build prod-like obligatoire sur starter - le
hot reload n'existe plus) :

```bash
make frontend-rebuild
```

### Étape 5 - Mesure

```bash
make k6           # ~ 6 min - fait `make restore` automatiquement
make lighthouse   # ~ 2-3 min - idem
```

Comparez avec les baselines starter (`docs/benchmarks/k6-starter/` et
`docs/benchmarks/lhci-starter/`).

## 3. Note technique - ISR vs SWR sur preset Node

Nuxt 4 expose deux primitives de cache de route au niveau `routeRules` :
`isr: <ttl>` et `swr: <ttl>`.

Sur **Vercel** ou **Cloudflare Workers**, `isr` (Incremental Static
Regeneration) délègue le cache à l'infrastructure de la plateforme
hôte. Sur un déploiement **Node classique** (preset `node-server`,
notre cas), seul `swr` produit un véritable **cache applicatif côté
serveur** via le storage mémoire Nitro (`cachedEventHandler`). On peut
le vérifier dans `.output/server/chunks/nitro/nitro.mjs` du build :

```js
"/": {
  "swr": 60,                                  // ← directive lue
  "cache": { "swr": true, "maxAge": 60 }      // ← engendre cachedEventHandler
}
```

vs avant le switch :

```js
"/": { "isr": 60 }                            // ← aucun bloc cache : no-op runtime
```

La sémantique « ISR » (génération statique incrémentale) est un terme
issu du marketing hébergeur ; le mécanisme sous-jacent en preset Node
est conceptuellement le même qu'un SWR avec revalidation en arrière-plan
— même TTL, même comportement « serve stale + revalidate background ».

**Pour activer un véritable ISR sur Node** il faudrait configurer
explicitement `nitro.storage.cache` (vers Redis ou disque persistant) +
ajouter un `cache` block dans les routeRules. Ce n'est pas demandé pour
l'atelier J1 - `swr` couvre largement la cible.


## 4. Métriques avant/après

Cf. `docs/benchmarks/j1-cdn-cache-comparison.md` pour le tableau complet.
Synthèse :

| Métrique                    | Starter | J1     | Cible J1 |
|-----------------------------|--------:|-------:|---------:|
| **Lighthouse home Perf**    |    0.55 |   0.76 |  > 0.75 ✅ |
| **Lighthouse home LCP**     |  16.3 s |  5.1 s |    < 6 s ✅ |
| **k6 home p95**             |   5.3 s |  2.1 ms|    < 2 s ✅ |
| Lighthouse fiche Perf       |    0.91 |   0.91 |   ≥ 0.90 ✅ |
| k6 search-load p95          |   2.9 s | 2.36 s |  inchangé (J3) |
| k6 checkout-stress p95      |  1.38 s |  1.29 s|  inchangé (J3) |

## 5. Smoke test à dérouler en fin d'atelier

```bash
# 1. Compression active ?
curl -sSI -H "Accept-Encoding: gzip" http://localhost:${NGINX_PORT}/ | grep -i content-encoding
# attendu : Content-Encoding: gzip

# 2. Cache-Control immutable sur _nuxt/* ?
NUXT_ASSET=$(curl -s http://localhost:${NGINX_PORT}/ | grep -oE '/_nuxt/[A-Za-z0-9_-]+\.js' | head -1)
curl -sSI "http://localhost:${NGINX_PORT}${NUXT_ASSET}" | grep -i cache-control
# attendu : cache-control: public, max-age=31536000, immutable

# 3. Cache-Control applicatif sur /api/v1/events ?
curl -sSI http://localhost:${NGINX_PORT}/api/v1/events | grep -i cache-control
# attendu : Cache-Control: max-age=60, public, s-maxage=60, stale-while-revalidate=300

# 4. SWR Nitro fonctionne ? (hits 2-N doivent être instantanés)
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -w "hit $i: %{time_starttransfer}s\n" http://localhost:${NGINX_PORT}/
done
# attendu : hit 1 ≈ 2s, hits 2-5 < 10ms

# 5. Tunnel d'achat toujours fonctionnel ?
TOKEN=$(curl -sS -X POST -H 'Content-Type: application/json' \
  -d '{"email":"visitor@demo.test","password":"password"}' \
  http://localhost:${NGINX_PORT}/api/v1/auth/login | jq -r .data.token)
curl -sS -H "Authorization: Bearer $TOKEN" http://localhost:${NGINX_PORT}/api/v1/me/tickets | jq '.data | length'
# attendu : nombre de tickets > 0
```

## 6. Ce qui n'est PAS dans cette branche

| Optimisation                                | Branche      |
|---------------------------------------------|--------------|
| `@nuxt/image`, `@nuxt/fonts`, `<NuxtImg>` hero | `solution/j2-bundle` |
| Code splitting Chart.js, lazy hydration      | `solution/j2-bundle` |
| Pagination cursor (front + back)             | `solution/j2-frontend` |
| Eager loading + Cache::remember + queues     | `solution/j3-laravel` |
| Octane + FrankenPHP, OPcache, JIT            | `solution/j3-laravel` |
| `keepalive` + `fastcgi_keep_conn` upstream FPM | `solution/j3-laravel` |
| Index Postgres + tuning postgresql.conf      | `solution/j3-postgres` |
| Simulation CDN amont (Varnish / Cloudflare)  | hors v1 (note §10 spec) |
