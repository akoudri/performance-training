# Lighthouse — Baseline starter (prod-like non optimisé)

> Audit Mobile / Slow 4G / 4× CPU throttling, 3 runs par URL, run
> représentatif sélectionné par LHCI (médiane). Mesures réalisées le
> **2026-05-08** sur la branche `main`, derrière le frontal Nginx,
> avec dataset **fraîchement restauré** via le pre-hook `make restore`
> intégré à la cible `make lighthouse`.

Reproduction : `make lighthouse` (cf. `docs/benchmarks/README.md`).

## Reproductibilité

`make lighthouse` exécute `make restore` automatiquement avant l'audit :
chaque mesure repart d'un dataset canonique. Pour itérer rapidement
sans reset (développement d'une branche solution), utiliser
`make lighthouse-no-restore`.

## Substrat de mesure

Starter prod-like NON optimisé : Nuxt
build prod servi par Node, PHP-FPM (pool 4-20, OPcache OFF), Nginx en
frontal sans gzip ni Cache-Control. Dataset réaliste (200 k tickets,
distribution star events). Endpoints listing renvoient TOUT (pas de
pagination).

---

## Scores Lighthouse (run représentatif)

| URL                                         | Performance | Accessibility | Best Practices | SEO  |
|---------------------------------------------|------------:|--------------:|---------------:|-----:|
| `/` (accueil)                               |    **0.55** |          0.94 |           1.00 | 1.00 |
| `/events/{star-slug}` (fiche événement)     |    **0.91** |          0.87 |           0.96 | 1.00 |

3 runs par URL : home perf stable à 0.55 ; fiche perf 0.89-0.91.

## Web Vitals (run représentatif)

| Métrique                | Home (`/`) | Fiche événement |
|-------------------------|-----------:|----------------:|
| LCP                     | **16.3 s** |       **3.0 s** |
| FCP                     |     15.9 s |           2.4 s |
| TTFB (server response)  |      2.3 s |           62 ms |

**Lecture** : la home reste très pénalisée parce que son SSR fetche
`/api/v1/events` (≈ 4 Mo de JSON sans pagination) avant de rendre le
HTML. La fiche événement n'a qu'un seul event à fetcher → TTFB 62 ms
et LCP 3.0 s limité par le poids de l'image hero JPEG full-size
(cf. `@perf-debt` images frontend, résolu en J2).

## Fichiers

- `home-mobile.html` / `.json` — rapport du run représentatif sur `/`.
- `event-detail-mobile.html` / `.json` — rapport du run représentatif
  sur `/events/{star-slug}`.
- `manifest.json` — manifest des 6 runs (3 par URL) produit par LHCI.

L'event mesuré est celui ayant le plus de tickets vendus dans le seed
réaliste (à la date de la mesure : id 412, slug
`necessitatibus-maiores-dolorem-nulla-id-412`, ~ 8 000 tickets vendus,
catégorie *concert*).

## Comparaison cible §9 spec

| Métrique                | Starter (mesuré) | Cible final §9 |
|-------------------------|-----------------:|---------------:|
| Score Performance home  |             0.55 |          ≥ 0.90 |
| Score Performance event |             0.91 |          ≥ 0.90 |
| LCP fiche événement     |            3.0 s |         < 1.5 s |

L'écart vers la cible final reste large sur le **bundle**, le
**caching HTTP**, l'**eager loading** côté Laravel et les **index
Postgres** — chacun couvert par une branche `solution/jX-name`.
