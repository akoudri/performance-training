# Comparatif `main` ↔ `solution/j2-bundle`

> Mesures réalisées le **2026-05-09** sur la stack Compose locale,
> dataset réaliste (200 k tickets) **fraîchement restauré** entre chaque
> run via le pre-hook `make restore` (intégré à `make k6` et
> `make lighthouse`).
>
> Substrat partagé : Nuxt prod-build + PHP-FPM (pool 4-20, OPcache OFF)
> + Nginx en frontal **non tuné**. Seules les optimisations applicatives
> de J2 changent entre les deux états — pas de cache HTTP, pas de
> compression, pas de SWR (ces optims sont l'objet de
> `solution/j1-cdn-cache`, indépendant).

---

## Scope J2

Quatre sous-systèmes touchés, isolés du reste :

1. **`@nuxt/image`** (`frontend/nuxt.config.ts` + `composables/useImageSrc.ts`
   + 3 fichiers Vue) — pipeline IPX server-side, AVIF/WebP négociés,
   srcset responsive, presets `hero` / `gallery` / `card`.
2. **`@nuxt/fonts`** (`nuxt.config.ts` — modules + config + retrait des
   `<link href=fonts.googleapis.com>`) — self-hosting Inter sous
   `/_fonts/<hash>.woff2`, `font-display: swap`, preload du poids 400.
3. **Code splitting Chart.js** (`components/SalesChart.vue` extrait,
   `layouts/organizer.vue` allégé, `pages/organizer/dashboard.vue`
   utilise `defineAsyncComponent`).
4. **Lazy hydration** (`components/TicketSelector.vue` extrait,
   `pages/events/[slug].vue` utilise `<LazyTicketSelector hydrate-on-idle>`).

Bump infrastructurel induit : Node 20-alpine → 22-alpine
(`infra/docker/frontend.Dockerfile` + `docker-compose.yml`
frontend-tools), pré-requis pour `@nuxt/image` v2 (`engines: node >= 22`).

---

## Tableau métriques avant/après

### k6 (mesure serveur, sans cache client)

| Scénario / Métrique                 | Starter   | J2 bundle    | Δ          |
|-------------------------------------|----------:|-------------:|-----------:|
| **homepage-load** http_reqs         |       351 |        407   |   +16 %    |
| homepage-load `http_req_duration` med | 4.4 s   |        3.8 s |   −13 %    |
| homepage-load p95                   |     5.3 s |        4.2 s |   −21 %    |
| **search-load** http_reqs           |     1 409 |       1 501  |   +6.5 %   |
| search-load med                     |    135 ms |       99 ms  |   −26 %    |
| search-load p95                     |     2.9 s |        2.2 s |   −25 %    |
| **checkout-stress** http_reqs       |     9 827 |     13 259   |   +35 %    |
| checkout-stress med                 |     40 ms |       32 ms  |   −20 %    |
| checkout-stress p95                 |    1.38 s |      1.21 s  |   −12 %    |
| checkout-stress fail_rate*          |    90.8 % |     93.2 %   |   stable   |

\* J2 fait +35 % d'itérations sur le même quota fini → ratio
mécaniquement plus haut. Les ~ 904 succès (201 Created) sont identiques
en valeur absolue ; le différentiel reste l'objet de
`solution/j3-laravel` (`SELECT FOR UPDATE SKIP LOCKED`, queues Redis).

### Lighthouse Mobile / Slow 4G / 4× CPU

| URL / Métrique               | Starter   | J2 bundle      | Δ            | Cible J2   |
|------------------------------|----------:|---------------:|-------------:|-----------:|
| **Home** Performance         |    0.55   |    **0.66**    |  +20 %       |  > 0.65 ✅ |
| Home LCP                     |   16.3 s  |    **5.6 s**   |  −66 %       |  < 8 s  ✅ |
| Home FCP                     |   15.9 s  |    **4.8 s**   |  −70 %       |    —       |
| Home TBT                     |     70 ms |       60 ms    |  −14 %       |    —       |
| Home TTFB                    |    2.3 s  |       1.9 s    |  −17 %       |    —       |
| **Fiche** Performance        |    0.91   |    **0.92**    |   +1 %       |  ≥ 0.90 ✅ |
| Fiche LCP                    |    3.0 s  |    **2.9 s**   |   −3 %       |  < 3 s  ✅ |
| Fiche FCP                    |    2.4 s  |       2.4 s    |  inchangé    |    —       |
| Fiche TBT                    |     10 ms |       10 ms    |  inchangé    |    —       |

### Bundle (`nuxi analyze`)

| Chunk client                                | Starter (raw)   | J2 (raw)       | Δ          |
|---------------------------------------------|----------------:|---------------:|-----------:|
| `entry.js`                                  | 196.80 kB       | 197.45 kB      | ~ inchangé |
| Chart.js (statique en starter)              | 161.10 kB       | (lazy chunk)   | déporté    |
| Chart.js (chunk async J2 — `SalesChart.vue`)| —               | 161.99 kB      | −          |
| `browser.js` (Nuxt internals)               |  25.84 kB       |  25.84 kB      | inchangé   |
| `TicketSelector.mjs` (lazy chunk)           | —               |   3.00 kB      | nouveau    |
| `qrcode` chunk                              | (déjà async)    | (déjà async)   | inchangé   |

**First Load JS par page** (gzipped, après tree-shaking) :

| Page                               | Starter     | J2 bundle   | Δ          |
|------------------------------------|------------:|------------:|-----------:|
| Home `/`                           | ~ 74 Ko     | ~ 74 Ko     | ~ inchangé |
| Fiche événement `/events/{slug}`   | ~ 76 Ko     | ~ 73 Ko     | −3 Ko (lazy hydration) |
| **Dashboard organizer**            | ~ 131 Ko    | ~ 75 Ko     | **−56 Ko (-43 %)** |
| Events list / edit / participants  | ~ 131 Ko    | ~ 75 Ko     | **−56 Ko (-43 %)** |

> Le gain First Load JS porte sur **toutes les pages organizer** parce
> que Chart.js était importé dans le layout `organizer.vue`, donc pris
> par `events/index`, `events/[id]/edit`, `events/[id]/participants`,
> alors qu'il n'est utile que sur `dashboard`.

### Compression d'image (mesure unitaire IPX)

| Image                   | JPEG original   | AVIF (J2)        | Δ           |
|-------------------------|----------------:|-----------------:|------------:|
| Hero 2048×640 (q_80)    |        283 Ko   |   ~ 169 Ko       |    −40 %    |
| Card 480×270 (q_70)     |        283 Ko   |    ~ 26 Ko       |    −91 %    |
| Galerie 480×270 (q_75)  |        283 Ko   |    ~ 30 Ko       |    −89 %    |

12 cards visibles sur la home → ~ 5 Mo (JPEG full-size) → ~ 320 Ko (AVIF
card preset). Économie réseau majeure sur Slow 4G.

---

## Décompte `@perf-debt` résolus

**5 marqueurs convertis en `@perf-fix:`** :

| Fichier                                 | Marqueur                                  | Statut  |
|-----------------------------------------|--------------------------------------------|---------|
| `frontend/nuxt.config.ts`               | pas de @nuxt/image                         | ✅ fix  |
| `frontend/nuxt.config.ts`               | pas de @nuxt/fonts                         | ✅ fix  |
| `frontend/app/pages/index.vue`          | hero sans `fetchpriority`/preload          | ✅ fix  |
| `frontend/app/pages/events/[slug].vue`  | hero sans `fetchpriority`/preload          | ✅ fix  |
| `frontend/app/pages/events/[slug].vue`  | TicketSelector sans lazy hydration         | ✅ fix  |
| `frontend/app/components/EventCard.vue` | image full-size sans srcset/AVIF           | ✅ fix  |
| `frontend/app/layouts/organizer.vue`    | Chart.js statique dans le layout           | ✅ fix  |

**Marqueurs préservés (réservés à d'autres branches solution)** :

| Fichier                                | Marqueur                                  | Réservé à                   |
|----------------------------------------|--------------------------------------------|-----------------------------|
| `frontend/nuxt.config.ts`              | pas de routeRules / SWR                   | `solution/j1-cdn-cache`     |
| `infra/nginx/*`                        | gzip/brotli/HTTP2/Cache-Control absents   | `solution/j1-cdn-cache`     |
| `frontend/app/pages/organizer/dashboard.vue` | ref global, polling re-render          | `solution/j2-dashboard`     |
| `frontend/app/pages/organizer/events/[id]/participants.vue` | pas de virtualisation | `solution/j2-dashboard`     |
| `backend/...`                          | N+1, no cache, sync queues, no opcache, etc. | `solution/j3-{laravel,postgres}` |

---

## Analyse (5-10 lignes)

La cible pédagogique de J2 — *« réduire le payload navigateur (JS +
images + polices) sans toucher au backend »* — est validée. Le **score
Performance home passe de 0.55 à 0.66** (+20 %) avec un saut FCP
spectaculaire (15.9 s → 4.8 s, −70 %) tiré par la suppression du
roundtrip Google Fonts (`@nuxt/fonts` self-hosting avec font-display
swap + preload ciblé du poids 400). Le **LCP home tombe à 5.6 s**
(−66 %) parce que le hero AVIF négocié pèse 169 Ko au lieu de 283 Ko
en JPEG full-size, et que les cards qui peuplent le viewport partagent
le même pipeline IPX (~ −91 % par card en preset card). La **fiche
événement reste à 2.9 s LCP** (parité starter à 0.1 s près) malgré le
surcoût d'IPX (fetch source + transform sharp) — net positif minimal,
parce que le starter n'avait pas de pathologie LCP majeure sur cette
page (TTFB 62 ms). Côté **bundles**, Chart.js (~162 Ko raw / 56 Ko
gzipped) est désormais isolé dans un chunk async tiré seulement par le
dashboard ; les pages organizer non-dashboard (events list, edit,
participants) gagnent **~ −56 Ko gzipped sur First Load JS (−43 %)**.
Le `TicketSelector` lazy-hydraté libère le main thread pour le LCP
hero de la fiche. **Aucun code backend ni Nginx n'a été modifié** —
la compression HTTP et le caching restent l'objet de
`solution/j1-cdn-cache` (Lighthouse l'identifie via
`uses-text-compression: 139 KiB savings`).

## Limitations connues

1. **Preload hero abandonné** : `<NuxtImg preload>` (et `useHead({
   link: rel=preload as=image })`) génèrent un preload pointant sur
   la plus grande variante srcset (2048w) parce que `imagesrcset`
   n'est pas écrit pour du width-based srcset. En Lighthouse Mobile
   (412 px viewport), le browser sélectionne pourtant `s_640x200` →
   double fetch, +0.8 s LCP fiche, +0.3 s LCP home. Workaround :
   `fetchpriority="high"` seul (suffit pour priorité critical-path
   sans mismatch). Cf. commit `fix(frontend): drop hero preload`.
2. **Image hero rendue après `_ipx/_/` dans le HTML** : l'URL source
   est encodée dans le path IPX (`/_ipx/<modifiers>/http://minio:9000/
   resonance/...`). Le hostname interne `minio` apparaît dans le
   HTML — fonctionnellement transparent (le browser ne fait que
   forwarder à `/_ipx/`), juste un peu inhabituel à voir.
3. **`uses-text-compression: 139 KiB savings`** dans Lighthouse — non
   adressable depuis J2 (Nginx non tuné), résolu en
   `solution/j1-cdn-cache`.
