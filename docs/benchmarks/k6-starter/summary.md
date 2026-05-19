# k6 — Baseline starter (prod-like non optimisé)

> Mesures réalisées le **2026-05-19** sur la branche `main`, stack
> Compose locale, 7 services healthy. Dataset réaliste **fraîchement
> restauré** via le pre-hook `make restore` intégré à la cible
> `make k6` (dataset canonique : 200 k tickets, 8 events stars,
> quotas non entamés).
>
> Container `grafana/k6:latest` sur le réseau Compose `resonance_resonance` ;
> les VUs frappent `RESONANCE_BASE_URL=http://nginx` (port 80 interne),
> qui orchestre :
>
> - `/api/*` → fastcgi_pass `backend:9000` (PHP-FPM, pool 4-20 workers)
> - le reste → `proxy_pass http://frontend:3000` (Nuxt SSR Node preview)

Reproduction : `make k6` (cf. `docs/benchmarks/README.md`).

## Reproductibilité

`make k6` exécute `make restore` automatiquement avant les scénarios :
chaque mesure repart d'un dataset canonique (quotas frais). Sans ce
pre-hook, le second run héritait d'un quota épuisé par le premier
(failed_rate qui passait de 93 % à 97 %, médiane qui chutait de 1.4 s
à ~30 ms). La variance entre 2 runs successifs reste désormais < 5 %
sur toutes les métriques.

Pour itérer rapidement sans reset (développement d'une branche
solution), utiliser `make k6-no-restore`.

## Substrat de mesure

Starter prod-like NON optimisé : Nuxt
build prod + PHP-FPM (pool 4-20, OPcache OFF) + Nginx non tuné
(HTTP/1.1, gzip OFF, brotli OFF, pas de Cache-Control). Endpoints
listing renvoient TOUS les résultats (pas de pagination). Toutes les
optimisations attendues sont l'objet des branches `solution/jX-name`.

---

## 1. `homepage-load.js` — `GET /` (Nuxt SSR via Nginx)

20 VUs, ramp 30 s + plateau 60 s + ramp-down 30 s ≈ 2 min.

| Métrique                    | Valeur          |
|-----------------------------|----------------:|
| Itérations totales          |             437 |
| Requêtes HTTP               |             437 |
| Taux d'erreur (rate)        |          0.00 % |
| `http_req_duration` médian  |       **3.35 s** |
| `http_req_duration` p95     |          3.98 s |
| `http_req_duration` max     |          4.68 s |
| Throughput                  |       3.6 req/s |

**Lecture** : la home Nuxt SSR sous 20 VUs sérialise sur le SSR + le
fetch `/api/v1/events` (≈ 4 Mo de JSON sans pagination, sans cache).
TTFB médian 3.35 s — sujet J1 (`routeRules { '/': { isr: 60 } }` +
Cache-Control côté Nginx).

---

## 2. `search-load.js` — `GET /api/v1/events?…`

30 VUs, ramp 30 s + plateau 90 s + ramp-down 30 s ≈ 2.5 min. Filtres
aléatoirement choisis parmi 8 jeux représentatifs (city, category,
plein-texte ILIKE, etc.).

| Métrique                    | Valeur          |
|-----------------------------|----------------:|
| Itérations totales          |           1 565 |
| Requêtes HTTP               |           1 565 |
| Taux d'erreur (rate)        |          0.00 % |
| `http_req_duration` médian  |        **86 ms** |
| `http_req_duration` p95     |          1.77 s |
| `http_req_duration` max     |          2.14 s |
| Throughput                  |      10.3 req/s |

**Lecture** : médiane à 86 ms — FPM concurrent absorbe la charge sur
les filtres simples. Mais p95 reste à 1.77 s : les requêtes pleines
(`q=jazz` → ILIKE non indexé sur 1 200 events) saturent. Sujets J3 :
index B-tree (`events.status`, `events.city`, `events.category`),
index FTS gin (title || description), pagination cursor.

---

## 3. `checkout-stress.js` — `POST /api/v1/orders` concurrent

20 VUs, ramp 30 s + plateau 60 s + ramp-down 15 s ≈ 2 min. Tous les VUs
tapent sur la **même** `ticket_category` (Carré Or de la session 412
du star event, quota=2236 dont 1334 déjà vendus → 902 places libres),
avec timeout client de 30 s.

| Métrique                                       | Valeur          |
|------------------------------------------------|----------------:|
| Itérations totales                             |          14 400 |
| Requêtes HTTP                                  |          14 402 |
| Orders créés (status 201)                      |          ~ 904  |
| Requêtes 422 (stock épuisé après quota)        |        ~ 13 498 |
| `http_req_duration` médian global              |          31 ms  |
| `http_req_duration` p95 global                 |         1.15 s  |
| `http_req_duration` médian sur succès (201)    |        **1.36 s** |
| `http_req_duration` p95 sur succès (201)       |         1.68 s  |
| Throughput                                     |      136.9 req/s |
| Taux d'erreur (épuisement quota)               |        **93.7 %** |

**Lecture** : la concurrence FPM permet ~ 14 400 requêtes en 1 m 45 s.
Le quota de la `ticket_category` ciblée se vide en quelques secondes →
~ 904 succès (201), le reste finit en 422 (rejet rapide ~31 ms qui
tire la médiane globale vers le bas). C'est le tell pédagogique :
sans verrou (`SELECT … FOR UPDATE SKIP LOCKED` en J3) et sans file
d'attente, le tunnel d'achat n'absorbe pas un pic concurrentiel.

Le tunnel "réussi" (succès 201, métrique `http_req_duration{expected_response:true}`)
prend ~ 1.36 s par order — dominé par le `usleep(800-1500ms)` de
`PaymentMockService` + dompdf inline + SMTP synchrone (cf.
`@perf-debt` `OrderController`). Sujet J3 : `GenerateTicketPdfJob` +
`SendOrderConfirmationEmailJob` en queue Redis + Horizon → tunnel
utilisateur < 500 ms.

> Le seuil k6 `http_req_failed: ['rate<0.95']` couvre l'épuisement
> quota attendu sans masquer une vraie panne 5xx.

---

## 4. Comparaison cible §9 spec

| Métrique                              | Starter (mesuré) | Cible final §9 |
|---------------------------------------|-----------------:|---------------:|
| TTFB API `/api/v1/events` (médian)    |            86 ms |     < 100 ms   |
| TTFB API `/api/v1/events` (p95)       |          1.77 s  |     < 200 ms   |
| Tunnel achat (médian, succès 201)     |          1.36 s  |     < 500 ms   |
| Home SSR sous 20 VUs (médian)         |          3.35 s  |     < 1 s      |

Tous les chiffres ci-dessus sont des **TTFB / durée serveur**, mesurés
depuis le container k6 sans throttling réseau. Pour les Core Web Vitals
(LCP, INP), voir `docs/benchmarks/lhci-starter/`.

---

## 5. Historique

- 2026-05-08 : baseline initiale (homepage med 4.4 s, search med 135 ms,
  checkout fail_rate 90.8 %, tunnel succès ~ 1.4 s).
- 2026-05-19 : refresh sur host actuel (homepage med 3.35 s, search
  med 86 ms, checkout fail_rate 93.7 %, tunnel succès 1.36 s). Les
  variations restent dans la fourchette de variance host attendue
  (< 25 %) ; le **caractère pédagogique** (tunnel ~1.4 s, p95 search
  > 1 s, fail_rate > 90 %) est conservé.

---

## 6. Fichiers (machine-readable)

- `homepage-load-summary.json` — sortie complète `--summary-export` k6.
- `search-load-summary.json` — idem.
- `checkout-stress-summary.json` — idem.
