# Comparatif `main` ↔ `solution/j1-cdn-cache`

> Mesures réalisées le **2026-05-09** sur la stack Compose locale,
> dataset réaliste (200 k tickets) **fraîchement restauré** entre chaque
> run via le pre-hook `make restore` (intégré à `make k6` et
> `make lighthouse`).
>
> Substrat partagé : Nuxt prod-build + PHP-FPM (pool 4-20, OPcache OFF)
> + Nginx en frontal. Seules les optimisations de J1 changent entre
> les deux états.

---

## Scope J1

Trois sous-systèmes touchés, isolés du reste :

1. **Nuxt** (`frontend/nuxt.config.ts`) — `routeRules` SWR sur `/` (60 s),
   `/events` (60 s), `/events/**` (300 s) + `ssr: false` sur
   `/organizer/**`. *Note technique : `swr` plutôt qu'`isr`, cf. fiche
   atelier — sur preset `node-server` `isr` est un no-op runtime.*
2. **Laravel** (`app/Http/Middleware/SetEventsCacheControl.php` +
   `routes/api.php`) — middleware HTTP qui pose `Cache-Control: public,
   max-age=…, s-maxage=…, stale-while-revalidate=…` sur les 3 endpoints
   publics de découverte d'événements.
3. **Nginx** (`infra/nginx/nginx.conf` + `infra/nginx/sites/resonance.conf`) —
   `gzip on` (level 6) + `brotli on` (level 6) ; `http2 on` (h2c) ;
   `Cache-Control: public, max-age=31536000, immutable` sur `/_nuxt/*` ;
   `Cache-Control: public, max-age=86400, swr=604800` sur `/media/*`.

---

## Tableau métriques avant/après

### k6 (mesure serveur, sans cache client)

| Scénario / Métrique                 | Starter   | J1 cdn-cache | Δ          |
|-------------------------------------|----------:|-------------:|-----------:|
| **homepage-load** http_reqs         |       351 |    **1 810** |    ×5.2    |
| homepage-load `http_req_duration` med | 4.4 s   |  **1.07 ms** | ÷4 100     |
| homepage-load p95                   |     5.3 s |    **2.14 ms** | ÷2 480   |
| **search-load** http_reqs           |     1 409 |       1 485  |     +5 %   |
| search-load med                     |    135 ms |      106 ms  |    −22 %   |
| search-load p95                     |     2.9 s |      2.36 s  |    −19 %   |
| **checkout-stress** http_reqs       |     9 827 |     11 652   |    +19 %   |
| checkout-stress med                 |     40 ms |       36 ms  |    −10 %   |
| checkout-stress p95                 |    1.38 s |      1.29 s  |     −7 %   |
| checkout-stress fail_rate           |    90.8 % |     92.2 %   |  inchangé  |

### Lighthouse Mobile / Slow 4G / 4× CPU

| URL / Métrique               | Starter   | J1 cdn-cache | Δ            | Cible J1   |
|------------------------------|----------:|-------------:|-------------:|-----------:|
| **Home** Performance         |      0.55 |     **0.76** |       +38 %  |   > 0.75 ✅ |
| Home LCP                     |    16.3 s |      **5.1 s** |    −69 %    |    < 6 s ✅ |
| Home FCP                     |    15.9 s |        3.0 s |       −81 %  |       —    |
| Home TTFB                    |     2.3 s |       **0 ms** | cache hit   |       —    |
| **Fiche** Performance        |      0.91 |        0.91  |    inchangé  |   ≥ 0.90 ✅ |
| Fiche LCP                    |     3.0 s |        3.0 s |    inchangé  |       —    |

### Compression (mesure unitaire `curl` sur `/`)

| Encoding             | Taille payload HTML |
|----------------------|--------------------:|
| identity (aucune)    |          20 127 b   |
| gzip                 |       **3 733 b** (×5.4) |
| brotli               |       **3 403 b** (×5.9) |

---

## Décompte `@perf-debt` résolus

**6 marqueurs convertis en `@perf-fix:`** :

| Fichier                                | Marqueur                                | Statut  |
|----------------------------------------|------------------------------------------|---------|
| `frontend/nuxt.config.ts`              | `routeRules` ISR/SWR absentes            | ✅ fix  |
| `infra/nginx/nginx.conf`               | `gzip off`                               | ✅ fix  |
| `infra/nginx/nginx.conf`               | brotli compilé non activé                | ✅ fix  |
| `infra/nginx/sites/resonance.conf`     | HTTP/1.1 only (pas de `http2`)           | ✅ fix  |
| `infra/nginx/sites/resonance.conf`     | pas de `Cache-Control` sur `/_nuxt/*`    | ✅ fix  |
| `infra/nginx/sites/resonance.conf`     | pas de `Cache-Control` sur `/media/*`    | ✅ fix  |

**Marqueurs préservés (réservés à d'autres branches solution)** :

| Fichier                                | Marqueur                                  | Réservé à                   |
|----------------------------------------|--------------------------------------------|-----------------------------|
| `infra/nginx/sites/resonance.conf`     | pas de `keepalive` upstream FPM            | `solution/j3-laravel`       |
| `frontend/nuxt.config.ts`              | pas de `@nuxt/image`, pas de `@nuxt/fonts` | `solution/j2-bundle`        |
| `frontend/app/pages/index.vue`         | pas de `fetchpriority="high"` hero         | `solution/j2-bundle`        |
| `frontend/app/layouts/organizer.vue`   | Chart.js statique                          | `solution/j2-bundle`        |
| 4 endpoints listing                    | pas de pagination                          | `solution/j2-frontend`      |
| `backend/...` (~ 50 marqueurs)         | N+1, no cache, sync queues, no opcache, …  | `solution/j3-{laravel,postgres}` |

---

## Analyse (5-10 lignes)

La cible pédagogique de J1 — *« cache HTTP browser + cache applicatif
serveur »* — est validée. **SWR Nitro** transforme la home (k6 médian
4.4 s → 1 ms, ×4 100 plus rapide après le 1ᵉʳ hit chaud) et permet à
Lighthouse de mesurer une page rendue en cache (TTFB 2.3 s → 0 ms),
faisant remonter le score Performance home de 0.55 à 0.76. La
**compression Nginx** divise le payload HTML par 5-6, ce qui débloque le
FCP (15.9 s → 3.0 s) sur Slow 4G. Les **endpoints API** (`search-load`,
`checkout-stress`) restent essentiellement inchangés — c'est attendu :
J1 pose des **headers** Cache-Control, mais aucun cache serveur côté
Laravel (objet de J3) ; et k6 n'a pas de cache client. La fiche
événement reste plafonnée à 3.0 s LCP par l'image hero JPEG full-size
(objet de J2). **Aucun code applicatif n'a été modifié** — seul le
substrat de cache et de délivrance, conformément au scope J1.
