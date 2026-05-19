# Synthèse finale - Voyage starter → final

> Page de synthèse pédagogique pour la formation. Les 5 branches
> solution Phase 5 sont mergées sur cette branche `final`. Cette fiche
> raconte le **voyage complet** depuis le starter, les
> gains de chaque branche, leur composition, et les leçons
> transférables en projet réel.
>
> Pour le détail chiffré : `docs/benchmarks/final-comparison.md`.
> Pour chaque atelier individuel : `docs/ateliers/j{1,2,3}-*.md`.

---

## 1. Le voyage en une page

Resonance starter tourne en mode prod-like volontairement
non optimisé : Nuxt 4 build prod + PHP-FPM + Nginx sans tuning, dataset
réaliste 200 k tickets. Lighthouse home perf = 0.55, LCP = 16 s, k6
home p95 = 5.3 s, k6 checkout fail_rate = 91 %.

Cinq branches solution résolvent chacune un sous-ensemble de la dette :

```
                ┌──────────────────────────────────────────┐
                │   solution/final  (5 merges, this branch)│
                │                                          │
   browser ──►  │  Nginx HTTP/2 + brotli + Cache-Control  │
                │  /api → Octane FrankenPHP (HTTP)         │
                │  /     → Nuxt 4 SSR (SWR Nitro 60-300s)  │
                │                                          │
                │  ┌────────────────────────────────────┐  │
                │  │ Laravel 13.7                       │  │
                │  │  • Eager loading + Resources       │  │
                │  │    whenLoaded() (49 → 3 SELECTs)   │  │
                │  │  • Cache Redis tags (TTL 60-300s)  │  │
                │  │  • Queues Redis + Horizon          │  │
                │  │  • Cursor pagination /events       │  │
                │  │  • Full-text to_tsvector('french') │  │
                │  └─────────┬──────────────────────────┘  │
                │            │ pgbouncer:6432              │
                │            ▼ (SCRAM passthrough)         │
                │  ┌────────────────────────────────────┐  │
                │  │ PgBouncer (transaction-mode)       │  │
                │  └─────────┬──────────────────────────┘  │
                │            ▼                             │
                │  ┌────────────────────────────────────┐  │
                │  │ Postgres 16 (tuné)                 │  │
                │  │  • shared_buffers=1GB, work_mem=16MB│ │
                │  │  • 9 index secondaires             │  │
                │  │  • GIN tsvector français           │  │
                │  └────────────────────────────────────┘  │
                │                                          │
                │  ┌────────────────────────────────────┐  │
                │  │ Frontend Nuxt 4 (build prod)       │  │
                │  │  • @nuxt/image IPX (AVIF/WebP)     │  │
                │  │  • @nuxt/fonts (Inter self-host)   │  │
                │  │  • Chart.js code-split async       │  │
                │  │  • shallowRef + v-memo dashboard   │  │
                │  │  • vue-virtual-scroller            │  │
                │  │    (DOM 42k → 315 nodes)           │  │
                │  └────────────────────────────────────┘  │
                └──────────────────────────────────────────┘
```

**Résultat composé** (cf. `docs/benchmarks/final-comparison.md`) :

- ✅ TTFB API médian : 135 ms → **3 ms** (×45)
- ✅ k6 search p95 : 2.9 s → **4.8 ms** (×604)
- ✅ k6 home SSR médian : 4.4 s → **1.3 ms** (×3 384)
- ✅ DOM participants : 42 062 nodes → **315** (÷ 133)
- ✅ Lighthouse home perf : 0.55 → **0.89** (1 pp sous cible 0.90)
- ⚠️ LCP : 16.3 → 3.29 s (−80 %, mais 32 % au-dessus cible 2.5 s)
- ❌ Tunnel achat médian 1.37 s (mock paiement @design) - leçon
  pédagogique, voir §4.4.

---

## 2. Les 5 branches en 5 paragraphes

### 2.1 - `solution/j1-cdn-cache` - couches HTTP / cache CDN

**Levier** : tuning Nginx (HTTP/2, gzip on, brotli on, Cache-Control
immutable sur `_nuxt/*`, SWR sur `/media/*`), `routeRules` Nitro
SWR (home 60 s, /events 60 s, /events/** 300 s, /organizer/** ssr off),
middleware Laravel `SetEventsCacheControl` pour cache HTTP applicatif.

**Gain isolé mesuré** (cf. `docs/benchmarks/lhci-j1-cdn-cache/`) :
- Lighthouse home perf 0.55 → 0.76, LCP 16.3 → 5.1 s (cache HIT
  Nitro SWR).
- k6 home p95 5.3 s → 2.14 ms (cache HIT côté Nitro).

**Leçon transférable** : le **cache de réponse HTML rendu** côté Node
(SWR Nitro) bénéficie même à un client sans cache (k6, crawler).
C'est différent d'un simple Cache-Control envoyé au browser - c'est
un cache **applicatif serveur** que Nitro gère.

### 2.2 - `solution/j2-bundle` - poids transféré et critical path

**Levier** : `@nuxt/image` (provider IPX, AVIF/WebP, srcset
width-based), `@nuxt/fonts` (self-host Inter, font-display swap,
preload poids 400), `defineAsyncComponent` sur Chart.js (code-split,
−43 % First Load JS organizer), lazy hydration `TicketSelector`
sur fiche événement.

**Gain isolé mesuré** (cf. `docs/benchmarks/lhci-j2-bundle/`) :
- Lighthouse home perf 0.55 → 0.66, FCP 15.9 → 4.8 s (suppression du
  roundtrip Google Fonts).
- AVIF hero −40 % de poids, AVIF card −91 %.

**Leçon transférable** : `<NuxtImg preload>` est **piégeux** sur un
srcset width-based - il preload la plus grande variante (2048w) au
lieu de celle réellement choisie par le browser sur mobile (640w),
ce qui double le poids. Utiliser `fetchpriority="high"` qui couvre
l'usage 80 % sans risque. Cf. mémoire interne
`feedback_nuxt_image_gotchas`.

### 2.3 - `solution/j2-dashboard` - réactivité Vue et virtualisation

**Levier** : éclatement du state dashboard en 3 `shallowRef` ciblés
(`stats`, `salesChart`, `events`) au lieu d'un `ref` global,
`v-memo="[ev.id, ev.title, ev.city, ev.status]"` sur les rows de la
table events, `RecycleScroller` (vue-virtual-scroller) sur la table
participants - DOM 42 062 → 226 nodes (−99.5 %),
`onScopeDispose` pour le cleanup du polling 10 s.

**Gain isolé mesuré** (cf. `docs/benchmarks/lhci-j2-dashboard/manual/`):
- INP click searchbox starter 104 ms → 24 ms (−77 %).
- TBT scroll 5 s : 9 572 → 0 ms (−100 %).
- FPS scroll 25 → 85 fps.

**Leçon transférable** : la **virtualisation devient critique** à
partir de quelques milliers de lignes - pas à cause du paint (le
browser sait optimiser), mais à cause des handlers DOM attachés
(click, hover, etc.) qui multiplient les listeners. `shallowRef` est
suffisant pour les arrays remplacés en bloc - pas besoin de `ref`
profond qui pose un Proxy par item.

### 2.4 - `solution/j3-laravel` - backend applicatif Laravel

**Levier** : eager loading (`with([...])` sur Event/Organizer/Stats/
Participants/Order/Ticket controllers) + Resources `whenLoaded()`
(index /events : 49 → 3 SELECTs), cache Redis avec tags
(`Cache::tags(['events'])->remember(...)`, TTL 60 s liste / 300 s
fiche), queues Redis + Horizon (PDF dompdf + SMTP déportés du
thread HTTP), pagination cursor sur `/api/v1/events`
(meta.next_cursor / per_page=20), Octane + FrankenPHP (remplace
PHP-FPM), OPcache + JIT tracing 64 Mo.

**Gain isolé mesuré** (cf. `docs/benchmarks/j3-laravel-comparison.md`):
- k6 search p95 2.9 s → 6.3 ms (−99.8 %).
- k6 home p95 5.3 s → 19.9 ms (−99.6 %).
- TTFB Lighthouse home 2.3 s → 18 ms.
- Tunnel throughput ~ 94 → ~ 644 req/s (×6.8).

**Leçon transférable** : Laravel 11+ pose
`cache.serializable_classes = false` par défaut (prévention gadget
chain). **Impossible de cacher des objets** Eloquent / Paginator /
Resource sans qu'ils deviennent `__PHP_Incomplete_Class` au reload.
**Cacher la réponse JSON sérialisée** (`->resolve()` ou
`->jsonSerialize()` puis `array` natif), pas l'objet brut. Cf.
mémoire interne `feedback_j3_laravel_gotchas`.

### 2.5 - `solution/j3-postgres` - base de données et frontal pool

**Levier** : 9 index secondaires (8 B-tree partiels + 1 GIN tsvector
français pour la recherche full-text), tuning `postgresql.conf`
(shared_buffers 1 GB, work_mem 16 MB, random_page_cost 1.1, etc.),
PgBouncer en frontal transaction-mode (SCRAM passthrough auth_query,
pas de hash committé), dualité de connexion Laravel pgsql / pgsql_direct.

**Gain isolé mesuré** (cf.
`docs/benchmarks/j3-postgres-comparison.md`) :
- EXPLAIN full-text events : Seq Scan 78 ms → Bitmap Index Scan via
  GIN 0.06 ms (×1262).
- k6 search p95 : 2.9 s → 890 ms (sans Redis cache).
- TTFB Lighthouse home : 2.3 s → 1.0 s.

**Leçon transférable** : pour qu'un index sur expression GIN soit
utilisé par le planner, l'expression dans la requête doit être
**strictement identique** à celle indexée (même `' '` entre title et
description !). PgBouncer en transaction pooling **interdit les
prepared statements cross-transaction** - forcer
`PDO::ATTR_EMULATE_PREPARES=true` côté pgsql et utiliser une
connexion `pgsql_direct` pour les migrations / seeders / dump qui
ont besoin de sessions longues.

---

## 3. Composition : pourquoi le merge final apporte plus que la somme

Les chiffres §2 sont des **gains isolés** mesurés sur chaque branche
solution seule. Sur `final`, certaines paires de branches **composent
multiplicativement** :

### 3.1 - Cache Redis (j3-laravel) + SWR Nitro (j1-cdn-cache)

- Sans cache : home /events fetche /api/v1/events à chaque visite,
  3 SELECTs avec joins, ~ 50-150 ms TTFB.
- j3-laravel seul : cache Redis → HIT à ~ 1-3 ms côté API, mais le
  SSR Nuxt re-render le HTML à chaque requête (parse JSON, vDOM,
  hydration markup).
- j1 seul : SWR Nitro cache le HTML rendu, mais sans cache Redis le
  premier MISS dans la fenêtre TTL paye le TTFB API complet.
- **Composé** : SWR Nitro cache le HTML, et même le 1ᵉʳ MISS dans la
  fenêtre TTL retombe sur le cache Redis (HIT à 1-3 ms) → la chaîne
  complète sert un cache HIT à ~ 1-3 ms en bout-en-bout.

Mesure k6 home p95 : starter 5.3 s → j1 seul 2.14 ms → j3-laravel seul
19.9 ms → **final 2.77 ms** - la composition aligne les deux caches
sans interférence.

### 3.2 - GIN tsvector (j3-postgres) + Cache Redis (j3-laravel)

- j3-postgres seul : full-text Bitmap Index Scan 0.06 ms - toujours
  exécuté à chaque requête, payé sur chaque MISS.
- j3-laravel seul : cache Redis 60 s, mais le MISS retombe sur ILIKE
  Seq Scan 78 ms (j3-laravel n'avait pas remplacé l'ILIKE).
- **Composé** : Le cache Redis (j3-laravel) absorbe les HITs à ~ 1-3 ms.
  Sur les MISS (~ 5 % du trafic k6 search), la requête tombe sur GIN
  Index Scan à 0.06 ms (j3-postgres) au lieu de Seq Scan 78 ms.

### 3.3 - Octane (j3-laravel) + PgBouncer (j3-postgres)

- Octane garde l'app en mémoire (workers persistants) → pas de
  bootstrap Laravel à chaque requête.
- PgBouncer en transaction-mode partage 25 backends PG entre les
  4-N workers Octane → pas de pression Postgres sous charge.
- **Composé** : Octane absorbe ×6.8 le throughput. Avec PgBouncer,
  cette charge ne sature pas Postgres. Sans PgBouncer, Octane à 8
  workers × 1 connection chacun atteindrait `max_connections=100`
  rapidement sous burst.

---

## 4. Leçons transférables (méthodologie générale)

### 4.1 - Mesurer avant d'optimiser

Chaque atelier a posé sa **mesure starter** AVANT de toucher au code,
puis re-mesuré APRÈS pour valider l'amélioration. Cette discipline
exclut les "optimisations" qui ne mesurent pas (réécriture de
"propreté" code sans bench, etc.). Le `make benchmark` (`restore` +
`lighthouse` + `k6`) automatise le protocole pour le rendre
reproductible - pre-hook `make restore` chaîné garantit qu'un run
mesure toujours le même état de données.

### 4.2 - Brief différentiel ≠ cibles absolues

Une branche solution isolée ne peut **pas** atteindre toutes les cibles
parce que celles-ci supposent la composition. Le brief de chaque
branche doit énoncer ses **gains différentiels attendus** (vs starter
sur son périmètre propre), pas les cibles absolues. Toute fiche
d'atelier sur ce repo a 2 encarts :
1. « Ce que cet atelier améliore (gains différentiels mesurés) » —
   tableau delta vs starter.
2. « Ce qui n'est PAS dans le périmètre » - cibles hors d'atteinte
   sur cette branche, attribution à la branche qui les apporte.

Cf. `docs/architecture.md` §"Cibles différentielles vs cibles absolues"
et mémoire interne `feedback_differential_targets`.

### 4.3 - Les régressions apparentes sur Lighthouse ne sont pas des régressions

Plusieurs branches solution isolées (notamment j3-laravel et
j3-postgres) ont produit des **régressions apparentes** sur LCP / FCP /
score Lighthouse vs starter. Ces régressions s'expliquent par la
**composition Web Vitals × throttling** Slow 4G / 4× CPU. Sur la
branche `final` (composée), elles disparaissent ou s'inversent en
gains majeurs (LCP home 16.3 s → 3.29 s = −80 %).

Le piège : juger une branche solution isolée sur les cibles produit
un faux négatif systématique. Briefer en différentiel d'abord.

### 4.4 - Latence métier vs dette technique

`PaymentMockService::process` simule un acquittement PSP synchrone
avec `usleep(rand(800, 1500) * 1000)`. Préservé en `@design` jusqu'en
`final` :

- **N'est pas une dette de perf** : c'est un comportement métier
  qu'on simule (le PSP réel a la même latence incompressible).
- **Justifie l'usage des queues** : autour du tunnel synchrone PSP,
  tout ce qui n'a pas besoin d'être bloquant (PDF dompdf, mail SMTP)
  passe en queue Redis async (j3-laravel) - le client reçoit le 201
  Order quand le PSP a acquitté, pas après que le PDF soit généré.

C'est la leçon : **identifier les bottlenecks métier incompressibles**
et **déporter tout ce qui les entoure** en async. Le tunnel passe
de 3-5 s starter (PSP + PDF + mail synchrones) à 1.37 s final (PSP
seul, le reste en queue).

### 4.5 - Concurrence : un atelier dédié à reprendre

`failed_rate` k6 checkout : 90.8 % starter → 98.75 % final. Le **nombre
absolu de succès** (~ 904) reste identique parce que le quota fixe
est saturé en quelques secondes - final l'absorbe juste plus vite
(×7.3 throughput).

Le levier manquant : `SELECT … FOR UPDATE SKIP LOCKED` sur
`ticket_categories.sold` pour sérialiser les acquittements de quota.
Hors périmètre Phase 5 (les 5 branches sont end-to-end perf, pas
correctness sous concurrence). Pistes pour un **atelier futur
"j3-concurrence"** :
- Verrou ligne pessimiste (FOR UPDATE SKIP LOCKED).
- File d'attente "réservation" (Redis sorted set TTL 5 min).
- Pattern Stripe-like : créer un PaymentIntent réservant le slot,
  confirmer le paiement = consommer le slot.

### 4.6 - Composition vs sommation : 5 branches ≠ 5 gains additionnés

Les gains du tableau §7 du final-comparison.md illustrent : le gain
composé excède souvent la somme des gains isolés (effet
multiplicatif des caches en cascade) MAIS le gain composé n'est
jamais la **somme** des branches - c'est le **maximum** ou un produit.

Exemple TTFB API médian :
- j3-postgres seul : 135 → 43 ms (gain 92 ms).
- j3-laravel seul : 135 → 3.1 ms (gain 132 ms).
- Final composé : 135 → 3.05 ms (gain 132 ms, dominé par j3-laravel).

j3-postgres ne « rajoute pas » de gain sur le hit cache j3-laravel —
le cache HIT vaut 1-3 ms quel que soit le coût du MISS sous-jacent.
Mais sur les MISS (~ 5 % du trafic), j3-postgres divise le MISS par
2-1000× selon la requête.

---

## 5. Méthodologie générale à reproduire en projet réel

À partir du voyage Resonance, retenir :

1. **Une mesure baseline figée** avant de toucher au code. Pour
   Resonance : Lighthouse + k6 + EXPLAIN starter committés dans
   `docs/benchmarks/{lhci,k6,sql}-starter/` avec procédure
   reproductible (`make benchmark`).

2. **Marqueurs `@perf-debt` / `@perf-fix` / `@design`** en code pour
   tracer les optimisations volontairement omises (starter) ou
   appliquées (solution), et celles préservées par design (mock
   PSP). `grep -rn "@perf-debt"` reste la source de vérité.

3. **Branches solution isolées + comparison.md par branche**, briefées
   en cibles différentielles. Ne pas viser les cibles absolues sur une branche
   solo.

4. **Pre-hook de restore** sur toute mesure : `make k6` et
   `make lighthouse` font `make restore` (SQL + pool MinIO) en
   pré-requis automatique. Sans ça, deux runs successifs sont
   incomparables (cf. j3-postgres seed-dump : sans pre-hook, le 2ᵉ
   run de checkout-stress hérite d'un quota épuisé → fail_rate
   91 % → 97 %, médiane 1.4 s → 30 ms).

5. **Composition validée sur une branche `final`** qui merge toutes
   les solutions. Les cibles absolues sont à vérifier UNIQUEMENT
   ici. Mesurer la composition révèle les **effets de
   composition multiplicatifs** (cache Redis × SWR Nitro) et les
   **régressions silencieuses** (cf. les fix(merge) commits ci-dessous).

6. **Documenter honnêtement les cibles non atteintes** plutôt que
   forcer la mesure. Sur `final`, 4 cibles atteintes, 2 quasi-
   atteintes (−1 pp), 2 hors scope par design (mock PSP, SKIP
   LOCKED). Le repo n'a **pas** essayé d'atteindre les cibles
   contradictoires avec le périmètre Phase 5 - pédagogiquement plus
   honnête que tordre la mesure.


---

## 6. Pour aller plus loin (au-delà de Phase 5)

Cibles partielles + atelier concurrence dédié :

- **LCP home < 2.5 s** : redimensionner source image hero
  (1920×1080 → 1280×720 max) au build, srcset density-based +
  preload, ou edge CDN (Cloudflare Images).
- **LCP fiche < 1.5 s** : même piste + delete `media` non-cover de
  l'eager loading sur fiche.
- **Score Perf ≥ 0.90** : gains automatiques si LCP et TBT s'améliorent.
- **Tunnel checkout 422 < 5 %** : atelier `j3-concurrence` (SELECT
  FOR UPDATE SKIP LOCKED, ou Redis sorted-set reservation pattern).
- **Tunnel checkout médian < 500 ms** : impossible sans changer le
  mock paiement, ce qui irait à l'encontre du `@design`. Acceptable
  comme limite pédagogique.

