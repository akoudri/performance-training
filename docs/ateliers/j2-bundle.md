# Atelier J2 - Bundle, images et polices

> **Branche solution** : `solution/j2-bundle`
> **Pré-requis** : avoir suivi les ateliers précédents OU connaître le
> contenu de `docs/architecture.md`

> **Note méthodologique - cibles différentielles vs absolues**
> (cf. `docs/architecture.md` §6
> "Cibles différentielles vs cibles absolues").
>
> Cet atelier livre des **gains différentiels** - l'apport propre de
> J2 mesuré contre le starter, périmètre `@nuxt/image` + `@nuxt/fonts`
> + code splitting + lazy hydration isolé. Les **cibles absolues**
> (LCP home < 1.5 s, score Performance home ≥ 0.90, etc.) supposent
> que **toutes** les optimisations sont composées sur la branche
> `final`. Cas d'école sur cette branche : LCP home **5.6 s atteint
> ici, pas 1.5 s** - bloqué par le TTFB SSR (~ 1.9 s) sans cache, qui
> sera résolu uniquement en mergeant `solution/j1-cdn-cache` (SWR
> Nitro). C'est exactement ce que la note méthodologique veut éviter
> de prendre pour un échec de J2 : c'est au contraire la signature
> attendue d'optimisations qui se composent.

### Ce que cet atelier améliore (gains différentiels mesurés)

Mesures vs `main` (cf. `docs/benchmarks/j2-bundle-comparison.md`) :

| Métrique                            | Starter | J2 bundle | Δ           |
|-------------------------------------|--------:|----------:|------------:|
| Lighthouse Performance home         |    0.55 |  **0.66** |   +20 %     |
| Lighthouse **LCP home**             |  16.3 s | **5.6 s** |   **−66 %** |
| Lighthouse **FCP home** (effet polices) | 15.9 s | **4.8 s** | **−70 %** |
| Lighthouse Performance fiche        |    0.91 |    0.92   |   +1 %      |
| Lighthouse LCP fiche                |   3.0 s |    2.9 s  |   −3 %      |
| First Load JS pages organizer non-dashboard | ~131 Ko | **~75 Ko gz** | **−43 %** |
| AVIF hero (1600×420) vs JPEG full-size | 283 Ko |  ~169 Ko |   −40 %     |
| AVIF card (480×270)                 |  283 Ko |  **~26 Ko** |  **−91 %** |

Le levier dominant sur la home est le **FCP −70 %** : la suppression
du roundtrip Google Fonts (DNS + TLS + fetch CSS bloquant) via
`@nuxt/fonts` + le HTML head plus léger débloquent le rendu critical-path.
Le LCP −66 % suit, tiré par l'image AVIF négociée + le retrait du
blocage CSS critical-path. Côté bundles, Chart.js (~ 162 Ko raw) sort
du First Load JS de toutes les pages organizer non-dashboard via
`defineAsyncComponent` sur `SalesChart`.

### Ce qui n'est PAS dans le périmètre

Les cibles suivantes restent **non atteignables** sur cette branche
isolée et seront couvertes en branche `final` :

- **LCP home < 1.5 s** (ou même < 3 s qu'on visait dans le brief J2) :
  J2 le fait passer de 16.3 s à **5.6 s**, gain substantiel (−66 %).
  Pourquoi pas 1.5 s ? Le TTFB SSR de la home reste à **~ 1.9 s**
  parce que la page Nuxt fetche `/api/v1/events` à chaque requête sans
  cache. J2 réduit le poids du LCP element et débloque le FCP, **mais
  ne touche pas au TTFB** - c'est exactement le scope de
  `solution/j1-cdn-cache` (SWR Nitro qui efface le TTFB après le 1ᵉʳ
  hit chaud). Sur `final`, j1 + j2 composent : TTFB ~ 0 ms (j1) +
  image légère + FCP débloqué (j2) → cible 1.5 s atteignable.
- **LCP fiche < 1.5 s** : reste à 2.9 s parce qu'IPX ajoute un peu
  d'overhead (fetch source MinIO + transform sharp) qui compense le
  gain AVIF - net positif minimal sur cette page qui n'avait pas de
  pathologie LCP majeure côté starter (TTFB 62 ms).
- **`uses-text-compression: 139 KiB savings`** dans les diagnostics
  Lighthouse : objet de J1 (gzip/brotli côté Nginx). Disparaît sur
  `final`.
- **TTFB API p95 < 200 ms / tunnel achat < 500 ms** : J2 ne touche
  pas au backend, ces cibles restent l'objet de J3
  (`solution/j3-laravel` + `solution/j3-postgres`).

Ces gains additionnels arrivent par composition lors du merge des
solutions sur la branche `final`.

## 1. Objectif pédagogique

Réduire le **payload transféré au navigateur** (JS + images + polices)
sans toucher au backend ni au caching HTTP, qui sont l'objet d'autres
branches solution. Quatre leviers complémentaires :

1. **`@nuxt/image` (provider IPX)** : pipeline de transformation
   server-side qui sert AVIF/WebP négociés, taille adaptative
   (`srcset` + `sizes`), avec resize/quality au build de la requête.
   Cible principale : LCP (image hero) et poids total des cards.
2. **`@nuxt/fonts`** : self-hosting de la police (Inter), `font-display:
   swap` implicite, preload du poids 400. Cible : suppression du
   round-trip Google Fonts + élimination du FOUT bloquant.
3. **Code splitting Chart.js** via composant `SalesChart` chargé en
   `defineAsyncComponent`. Cible : First Load JS des pages organizer
   autres que le dashboard (events list, edit, participants).
4. **Lazy hydration** d'un composant lourd below-fold (`TicketSelector`
   sur la fiche événement). Cible : libérer le main thread pour le LCP.

Résultat mesuré vis-à-vis du starter (cf.
`docs/benchmarks/j2-bundle-comparison.md`) :

- **LCP home** Lighthouse Mobile (Slow 4G + 4× CPU) : **16.3 s → 5.6 s**
  (−66 %). Le levier dominant est en réalité le **FCP**, qui passe
  de 15.9 s à 4.8 s (−70 %) parce qu'on a retiré le roundtrip Google
  Fonts (DNS + TLS + fetch CSS bloquant en starter).
- **LCP fiche événement** : **3.0 s → 2.9 s** (parité, le starter
  n'avait pas de pathologie majeure ici).
- **First Load JS pages organizer non-dashboard** :
  **~131 Ko → ~75 Ko gzipped (−43 %)** via Chart.js code-split.
- **Score Performance home** : **0.55 → 0.66** (+20 %) ; **fiche** :
  **0.91 → 0.92** (stable).

## 2. Énoncé pas-à-pas (depuis `main`)

```bash
git checkout main
git pull
git checkout -b mon-atelier-j2-bundle
```

### Étape 1 - Installer `@nuxt/image`

@nuxt/image v2 requiert Node ≥ 22. Avant l'install, bumpe
`infra/docker/frontend.Dockerfile` (builder + runtime) et le service
`frontend-tools` dans `docker-compose.yml` de `node:20-alpine` à
`node:22-alpine`.

```bash
docker compose --profile tools run --rm frontend-tools \
  npm install --save @nuxt/image@latest
```

Configure `frontend/nuxt.config.ts` :

```ts
modules: [
  '@nuxtjs/tailwindcss',
  '@pinia/nuxt',
  '@nuxt/eslint',
  '@nuxt/image',
],

image: {
  provider: 'ipx',
  // host:port exact (cf. parseURL(input).host dans @nuxt/image —
  // un domain sans port ne matche pas une URL avec port).
  domains: ['minio:9000', 'localhost:9000'],
  format: ['avif', 'webp'],
  quality: 75,
  screens: { sm: 640, md: 768, lg: 1024, xl: 1280, '2xl': 1536 },
  presets: {
    hero: { modifiers: { format: 'avif,webp', quality: 80, fit: 'cover' } },
    gallery: { modifiers: { format: 'avif,webp', quality: 75, fit: 'cover' } },
    card: { modifiers: { format: 'avif,webp', quality: 70, fit: 'cover' } },
  },
},
```

### Étape 2 - Helper `useImageSrc` (réécriture URL pour IPX server-side)

Le backend Laravel sérialise `cover_image_url` sous la forme
`http://localhost:9000/resonance/...` - c'est valide côté browser, mais
**pas** côté Nitro/IPX (qui tourne dans le container `frontend`, où
`localhost:9000` = le container lui-même, pas MinIO).

Crée `frontend/app/composables/useImageSrc.ts` :

```ts
export function useImageSrc(url: string | null | undefined): string {
  if (!url) return ''
  // Le browser ne fetche pas la source directement : il fetche
  // /_ipx/<modifiers>/<source>, et c'est IPX (server-side) qui fait le
  // fetch HTTP réel. Réécriture safe côté client.
  return url.replace('://localhost:9000/', '://minio:9000/')
}
```

Pattern dual analogue à `apiBase` / `apiBaseInternal` pour `/api/*`.

### Étape 3 - Migrer les `<img>` vers `<NuxtImg>`

Trois surfaces prioritaires :

#### a) Hero home (`pages/index.vue`)

```vue
<script setup lang="ts">
const heroImg = useImage()
useHead(() => {
  if (!hero.value?.cover_image_url) return {}
  const src = useImageSrc(hero.value.cover_image_url)
  return {
    link: [{
      rel: 'preload',
      as: 'image',
      href: heroImg(src, { preset: 'hero', width: 1920, height: 600 }),
      fetchpriority: 'high',
    }],
  }
})
</script>

<template>
  <NuxtImg
    v-if="hero.cover_image_url"
    :src="useImageSrc(hero.cover_image_url)"
    :alt="hero.title"
    preset="hero"
    width="1920" height="600"
    sizes="100vw sm:100vw md:100vw lg:100vw"
    fetchpriority="high" loading="eager"
    class="absolute inset-0 h-full w-full object-cover opacity-50"
  />
</template>
```

#### b) Hero + galerie fiche événement (`pages/events/[slug].vue`)

Hero : même pattern que home, mais resize 1600×420.
Galerie média : `preset="gallery"`, `loading="lazy"`, sizes
`50vw sm:33vw md:33vw lg:300px`.

#### c) Cards (`components/EventCard.vue`)

`preset="card"`, `width="480" height="270"`, `loading="lazy"`,
sizes `100vw sm:50vw lg:25vw xl:300px`.

### Étape 4 - Self-hosting Inter via `@nuxt/fonts`

```bash
docker compose --profile tools run --rm frontend-tools \
  npm install --save @nuxt/fonts@latest
```

Dans `nuxt.config.ts` :

```ts
modules: [/* … */, '@nuxt/fonts'],

fonts: {
  families: [
    // Preload Regular (corps de texte LCP) ; les autres poids
    // chargent sans preload pour ne pas saturer le critical path.
    { name: 'Inter', weights: [400], styles: ['normal'], preload: true },
    { name: 'Inter', weights: [500, 600, 700], styles: ['normal'] },
  ],
  defaults: {
    fallbacks: { 'sans-serif': ['system-ui', 'sans-serif'] },
  },
},

app: {
  head: {
    // Remove the Google Fonts <link> entries - @nuxt/fonts les remplace.
    link: [],
  },
},
```

### Étape 5 - Code splitting Chart.js

Créez `~/components/SalesChart.vue` qui encapsule l'import Chart.js,
le `Chart.register()`, le canvas et le cycle de vie. Le composant
reçoit `points: SalesPoint[]` en prop.

Dans `layouts/organizer.vue` : **supprimez tous les imports `chart.js`**.
Dans `pages/organizer/dashboard.vue` :

```ts
const SalesChart = defineAsyncComponent({
  loader: () => import('~/components/SalesChart.vue'),
  loadingComponent: () => h('div', {
    class: 'h-64 grid place-items-center text-sm text-slate-400',
  }, 'Chargement de la courbe…'),
})
```

```vue
<SalesChart :points="dashboardState.salesChart" />
```

### Étape 6 - Lazy hydration `TicketSelector`

Extraire la carte de billetterie (`<aside>`) de `pages/events/[slug].vue`
dans `~/components/TicketSelector.vue` (avec son state local :
`selectedSessionId`, `quantities`, `totalCents`, callbacks).

Dans la page :

```vue
<LazyTicketSelector
  hydrate-on-idle
  :event="event"
  :sessions="sessions"
/>
```

`hydrate-on-idle` (Nuxt 4) rend le HTML en SSR (utilisateur voit l'aside
tout de suite) mais **diffère l'hydratation** (binding handlers,
watchers, computed) jusqu'à `requestIdleCallback`. Conséquence : le main
thread reste libre pour le LCP du hero pendant la stabilisation
initiale.

### Étape 7 - Mesure

```bash
make frontend-rebuild   # multi-stage Docker rebuild
make k6                 # ~ 6 min - auto restore
make lighthouse         # ~ 2-3 min - auto restore
make frontend-typecheck # vérification TS
```

Bundle analyzer :

```bash
docker compose --profile tools run --rm frontend-tools \
  npx nuxi analyze --no-serve
```

Comparez aux baselines `docs/benchmarks/lhci-starter/` /
`docs/benchmarks/k6-starter/`.

## 3. Note technique - `domains` avec port (gotcha @nuxt/image)

`@nuxt/image` valide les URLs externes via `parseURL(input).host` qui
retourne **`hostname:port`** (pas seulement `hostname`). Si tu écris
`domains: ['minio']` et que ton URL est `http://minio:9000/...`,
la validation échoue : la srcset retombe sur l'URL brute (pas de
transformation IPX, pas d'AVIF). Les srcset sont alors identiques pour
tous les width descriptors - signe visuel net.

Vérification : `view-source:http://localhost:8081/` puis grep
`<img.*srcset` - chaque entrée doit avoir un `/_ipx/<modifiers>/...`
distinct par width.

## 4. Note technique - Pourquoi le pattern dual `useImageSrc` ?

Le backend Laravel sérialise `Storage::disk('s3')->url($path)` sous
`http://localhost:9000/resonance/<path>` parce qu'`AWS_URL` pointe sur
le port host MinIO. C'est l'URL que reçoit le browser dans le payload
JSON - elle est donc valide côté client.

Mais l'IPX provider de `@nuxt/image` tourne **server-side** dans le
container `frontend`, et là `localhost:9000` ne pointe pas sur MinIO,
ça pointe sur le container lui-même. Si on passe l'URL brute à
`<NuxtImg>`, IPX tente de fetch `http://localhost:9000/...` depuis le
frontend → connection refused.

Le helper `useImageSrc(url)` réécrit `localhost:9000` → `minio:9000`
de manière **inconditionnelle**. C'est safe côté browser parce que
le browser ne fetche **jamais** l'URL source telle quelle : il fetche
`/_ipx/<modifiers>/<source>` (où `<source>` est encodé), et IPX —
server-side, sur le réseau Compose - fait le fetch HTTP réel via
`http://minio:9000/...`.

Pattern analogue à `apiBaseInternal` / `apiBase` pour `/api/*`
(cf. composables/useApi.ts).


## 5. Métriques avant/après

Cf. `docs/benchmarks/j2-bundle-comparison.md` pour le tableau complet.
Synthèse Lighthouse (Mobile / Slow 4G / 4× CPU, médian de 3 runs) :

| Métrique                    | Starter | J2 bundle | Cible J2 |
|-----------------------------|--------:|----------:|---------:|
| **Lighthouse home Perf**    |    0.55 |  **0.66** |  > 0.65 ✅ |
| Lighthouse home LCP         |  16.3 s |  **5.6 s**|   < 8 s ✅ |
| Lighthouse home FCP         |  15.9 s |   4.8 s   |    -     |
| Lighthouse fiche Perf       |    0.91 |    0.92   |  ≥ 0.90 ✅ |
| Lighthouse fiche LCP        |   3.0 s |   2.9 s   |   < 3 s ✅ |
| First Load JS organizer     | ~131 Ko | ~ 75 Ko gz |   - (−43 %) |
| AVIF hero (1600×420)        | 283 Ko (JPEG) | ~169 Ko |  - (−40 %) |
| AVIF card (480×270)         | 283 Ko (JPEG) |  ~26 Ko |  - (−91 %) |

## 6. Smoke test à dérouler en fin d'atelier

```bash
NGINX_PORT=${NGINX_PORT:-8081}

# 1. IPX renvoie de l'AVIF avec Accept: image/avif ?
#    Note : on utilise les modifiers explicites (f_avif,webp&q_70&...)
#    parce que c'est ce que <NuxtImg> écrit dans srcset. Le shortcut
#    `preset_card/...` retombe sur le format source en URL directe
#    (gotcha @nuxt/image - les presets sont matérialisés au build).
curl -sSI -H 'Accept: image/avif' \
  "http://localhost:${NGINX_PORT}/_ipx/f_avif,webp&q_70&fit_cover&s_480x270/http://minio:9000/resonance/seed-pool/img-16-9-01.jpg" \
  | grep -i content-type
# attendu : Content-Type: image/avif

# 2. Comparaison de poids JPEG vs AVIF (card) :
ORIG=$(curl -sSI "http://localhost:9000/resonance/seed-pool/img-16-9-01.jpg" | grep -i content-length | awk '{print $2}' | tr -d '\r')
echo "JPEG original : $ORIG bytes"
curl -sS -o /tmp/card.avif -H 'Accept: image/avif' \
  "http://localhost:${NGINX_PORT}/_ipx/f_avif,webp&q_70&fit_cover&s_480x270/http://minio:9000/resonance/seed-pool/img-16-9-01.jpg"
ls -la /tmp/card.avif
# attendu : ~ -90 % vs JPEG original.

# 3. Polices self-hostées ?
curl -sS "http://localhost:${NGINX_PORT}/" | grep -oE '/_fonts/[^"]+\.woff2' | head -3
# attendu : 1 ou plusieurs URLs /_fonts/<hash>.woff2

curl -sS "http://localhost:${NGINX_PORT}/" | grep -ciE 'fonts.googleapis|fonts.gstatic'
# attendu : 0

# 4. Chart.js bien dans un chunk séparé ?
docker compose exec -T frontend sh -c \
  "for f in .output/public/_nuxt/*.js; do
     if grep -q 'Chart.js v' \$f; then
       echo \"Chart.js dans \$f (\$(stat -c %s \$f) bytes)\"
     fi
   done"
# attendu : un fichier dédié, ~ 162 Ko raw.

# 5. Hydratation différée du TicketSelector ?
SLUG=$(curl -sS http://localhost:${NGINX_PORT}/api/v1/events \
  | grep -oE '"slug":"[^"]+"' | head -1 | sed 's/"slug":"//;s/"//')
curl -sS "http://localhost:${NGINX_PORT}/events/$SLUG" \
  | grep -o '<aside class="lg:sticky[^"]*"' | head -1
# attendu : <aside …> rendu en SSR (HTML présent dès la 1re requête)

# 6. Parcours visiteur fonctionnel ?
TOKEN=$(curl -sS -X POST -H 'Content-Type: application/json' \
  -d '{"email":"visitor@demo.test","password":"password"}' \
  http://localhost:${NGINX_PORT}/api/v1/auth/login | jq -r .data.token)
curl -sS -H "Authorization: Bearer $TOKEN" \
  http://localhost:${NGINX_PORT}/api/v1/me/tickets | jq '.data | length'
# attendu : nombre de tickets > 0
```

## 7. Ce qui n'est PAS dans cette branche

| Optimisation                                | Branche                |
|---------------------------------------------|------------------------|
| `routeRules` SWR, `Cache-Control`, gzip/brotli, HTTP/2 | `solution/j1-cdn-cache` |
| `vue-virtual-scroller` participants          | `solution/j2-dashboard` |
| `shallowRef`, `v-memo` dashboard            | `solution/j2-dashboard` |
| `<NuxtLink prefetch-on=visibility>` cards   | `solution/j2-dashboard` |
| Pagination cursor backend (`cursorPaginate`)| `solution/j3-laravel`  |
| Eager loading + Cache::remember + queues    | `solution/j3-laravel`  |
| Index Postgres + tuning postgresql.conf     | `solution/j3-postgres` |
