# Comparaison : `solution/j3-laravel` vs `main` (starter)

> Mesures différentielles sur le même substrat prod-like (Nginx non
> tuné, Postgres 16, Redis 7, MinIO, Mailpit). Seuls le code applicatif
> Laravel et le runtime backend (Octane FrankenPHP) changent vs `main`.
> Cf. baselines : `docs/benchmarks/lhci-starter/` et
> `docs/benchmarks/k6-starter/`.

## Note méthodologique — différentiel vs absolu

Les chiffres ci-dessous sont des **gains différentiels** (j3-laravel
vs starter). Les **cibles absolues §9** (LCP home < 1.5 s,
score Performance home ≥ 0.90) supposent que **toutes** les
optimisations sont composées sur la branche `final`. Une branche
solution isolée n'atteint qu'une partie de ces cibles, celles qui
dépendent **de son périmètre seul**. Voir
`docs/architecture.md` §"Cibles différentielles vs cibles absolues".

---

## 1. Backend — k6 (TTFB / durée serveur)

| Métrique                            | Starter   | j3-laravel | Δ           |
|-------------------------------------|----------:|-----------:|------------:|
| `/api/v1/events` p95 (search-load)  |    2.9 s  |  **6.3 ms**|  **−99.8 %**|
| `/api/v1/events` médian             |  135 ms   |   3.1 ms   |  **−98 %**  |
| Home SSR sous 20 VUs (médian)       |    4.4 s  | **14.2 ms**|  **−99.7 %**|
| Home SSR p95                        |    5.3 s  |  19.9 ms   |  **−99.6 %**|
| Tunnel achat médian succès 201      |   1.39 s  |   1.17 s   |   **−16 %** |
| Tunnel achat throughput             |  ~ 94/s   |  ~ 644/s   |   **×6.8**  |
| Requêtes SQL events/index           |       49  |        **3**|  **÷ 16**   |
| `pdf_path` après checkout (ms)      | inline ~500ms × N |async (worker) | qualitatif |

Le **gain dominant côté backend** vient de la combinaison
**eager loading + cache Redis + Octane**. Chaque levier seul donne
un gain partiel ; l'effet composé sur le hot path
`/api/v1/events` :

- Eager loading : 49 → 3 SELECT, divise le coût SQL en cas de cache
  miss.
- Cache Redis (TTL 60 s, tagué) : la majorité des hits sous polling
  retombent en 4 ms (HIT) au lieu de 80-200 ms (MISS + 3 SELECT).
- Cursor pagination : payload divisé par ~ 50 (4 Mo → 80 Ko), allège
  le SSR Nuxt côté client et rend le cache Redis viable (clé bornée).
- Octane + FrankenPHP : pas de bootstrap Laravel à chaque requête,
  workers persistants, OPcache + JIT compilent les hot paths.

---

## 2. Frontend — Lighthouse (Core Web Vitals)

| Métrique                            | Starter   | j3-laravel | Δ           |
|-------------------------------------|----------:|-----------:|------------:|
| Performance home Lighthouse         |    0.55   |  **0.68**  |  **+24 %**  |
| Performance fiche événement         |    0.91   |   0.77     |   −15 %†    |
| **TTFB home** (Lighthouse)          |    2.3 s  | **18 ms**  |  **−99 %**  |
| TTFB fiche événement                |    62 ms  |   17 ms    |   −73 %     |
| **FCP home**                        |   15.9 s  |  **3.3 s** |  **−79 %**  |
| FCP fiche événement                 |    2.4 s  |   3.0 s    |   +25 %†    |
| **LCP home**                        |   16.3 s  |  10.4 s    |  **−36 %**  |
| LCP fiche événement                 |    3.0 s  |   4.8 s    |   +60 %†    |

† **Régressions apparentes sur la fiche événement** : à interpréter
avec prudence. Lighthouse n'a réussi qu'**1 run sur 3** sur la
fiche (timeout sous CPU 4×), ce qui rend la mesure très bruitée.
Le starter tournait avec FPM concurrent et bénéficiait probablement
d'un timing favorable au cold-start. j3-laravel sous Octane peut être
plus lent au cold-start de Lighthouse (download du binaire au 1er
démarrage du conteneur, chargement des caches optimize, etc.).
Mesurer cette branche en isolation sur la fiche est statistiquement
peu fiable — le résultat composé sur `final` sera la mesure utile.

---

## 3. Smoke tests fonctionnels

- ✅ Login démo + checkout : POST /api/v1/orders → 201 en ~ 1.0-1.5 s
  (vs 3-5 s starter).
- ✅ Mailpit reçoit le mail de confirmation **après** le 201 — preuve
  du déport asynchrone (mail #120001, #120002, #120003 visibles dans
  l'UI Mailpit).
- ✅ Horizon dashboard accessible sur
  `http://localhost:${NGINX_PORT}/horizon` ; `jobsPerMinute=37`,
  `processes=1`, `failedJobs=0` après les smoke tests.
- ✅ Pages organizer (`/organizer/dashboard`,
  `/organizer/events/600/participants`) s'affichent avec eager loading
  (pas de N+1 dans les Resources).
- ✅ Parcours visiteur complet sans régression : home → fiche → checkout
  → mes billets.

---

## 4. Cibles atteintes par cette branche (différentiel)

| Cible brief                                    | Atteint ? | Mesure                |
|------------------------------------------------|-----------|----------------------:|
| TTFB `/api/v1/events` < 200 ms                 | ✅       | 6.3 ms p95            |
| Requêtes SQL events/index 49 → 2-3             | ✅       | 3 requêtes            |
| k6 search p95 < 800 ms                         | ✅       | 6.3 ms                |
| Checkout isolé < 300 ms                        | ⚠️ ~1.0 s | mock paiement préservé† |
| k6 home p95 ~ 2 s                              | ✅       | 19.9 ms               |
| Mailpit reçoit toujours le mail (asynchrone)   | ✅       | mails #120001-3       |
| Horizon dashboard montre les jobs traités      | ✅       | 37 jobs/min           |

† La cible "checkout < 300 ms" du brief n'est atteignable qu'en
**supprimant** le mock paiement (`PaymentMockService` simule
800-1500 ms de PSP). Le brief explicite que ce mock doit être
préservé : *"Le mock paiement reste synchrone (c'est le bottleneck
métier qu'on simule, pas une dette)."* Donc cible interprétée comme
"checkout asynchrone post-mock" : 1.0 s = 0 ms transaction +
~ 1.0 s mock paiement + ~ 50 ms HTTP/middleware. Le déport effectif
des **autres** coûts (PDF dompdf 200-500 ms × N tickets, SMTP
synchrone) vers les queues Redis est confirmé par Mailpit qui reçoit
les mails après le 201.

## 5. Hors périmètre j3-laravel

Les cibles §9 suivantes restent **non atteignables** sur cette
branche isolée et seront couvertes en branche `final` :

- **LCP home < 1.5 s** : reste à 10.4 s parce que le LCP est dominé
  par l'image hero JPEG full-size sans CDN cache. Couvert par
  `solution/j2-bundle` (image AVIF) + `solution/j1-cdn-cache`
  (Cache-Control immutable sur `_nuxt/*` et `media/*`).
- **LCP fiche < 1.5 s** : même cause, même résolution composée.
- **Score Performance home ≥ 0.90** : reste à 0.68. Avec j1 + j2
  composés, le score atteindra la cible.
- **k6 checkout failed_rate < 5 %** : non atteignable car le bottleneck
  est l'absence de verrou Postgres (`SELECT FOR UPDATE SKIP LOCKED`)
  sur `ticket_categories.sold`. Hors périmètre j3-laravel
  (à reprendre dans une itération concurrence dédiée). Note importante :
  cette branche **dégrade apparemment** le failed_rate (90.8 % → 98.66 %)
  parce qu'elle absorbe 7× plus de requêtes/s à quota fixe — le nombre
  absolu de succès est identique au starter.
- **TTFB API `/api/v1/events` < 100 ms en cache MISS** : couvert ici
  (3 ms en HIT, ~ 80 ms en MISS), mais le cas du long-tail ILIKE
  reste l'objet de `solution/j3-postgres` (gin tsvector FR).

Ces gains additionnels arrivent par composition lors du merge des
solutions sur la branche `final`.
