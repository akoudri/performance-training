# Comparatif `main` ↔ `solution/j2-dashboard`

> Mesures réalisées le **2026-05-09** sur la stack Compose locale,
> dataset réaliste (200 k tickets) **fraîchement restauré** entre
> chaque run via `make restore` (DB + pool MinIO canoniques).
>
> Substrat partagé : Nuxt prod-build + PHP-FPM (pool 4-20, OPcache
> OFF) + Nginx en frontal **non tuné**. Seules les optimisations
> applicatives Vue 3 de J2-dashboard changent entre les deux états —
> pas de cache HTTP (j1), pas d'images optimisées (j2-bundle), pas
> de pagination cursor backend (j3-laravel), pas d'index Postgres
> (j3-postgres).
>
> Méthode de mesure : Chrome DevTools MCP automatisé, throttling
> Mobile / Slow 4G / 4× CPU (parité avec config Lighthouse).
> Données brutes dans `lhci-j2-dashboard/manual/{before,after}/`.

---

## Scope J2-dashboard

Quatre sous-systèmes touchés sur le frontend Vue 3, isolés du reste :

1. **Refonte du state dashboard** (`frontend/app/pages/organizer/dashboard.vue`) —
   `dashboardState` ref monolithique éclaté en trois refs ciblés
   (`stats`, `salesChart`, `events`), avec `shallowRef` sur les deux
   collections (le code remplace l'array entier, pas de mutation par
   item). Suppression de la réassignation totale (`dashboardState.value
   = { ... }`) qui invalidait l'arbre Vue complet à chaque tick de
   polling.
2. **`v-memo` sur la table d'événements dashboard**
   (`frontend/app/pages/organizer/dashboard.vue`) — chaque `<tr>` porte
   `v-memo="[ev.id, ev.title, ev.city, ev.status]"` (les 4 champs
   réellement lus par le row template). Vue saute entièrement le diff
   d'une ligne dont aucun de ces 4 champs n'a changé entre deux ticks.
3. **Virtualisation de la table participants**
   (`frontend/app/pages/organizer/events/[id]/participants.vue`) —
   `vue-virtual-scroller` (`RecycleScroller`), table `<table>/<tr>`
   migrée vers une grille CSS de 5 colonnes fixes. `participants` passé
   en `shallowRef` pour éviter le coût des Proxies sur 7000 items.
   Convention `data-row` sur chaque ligne pour la mesure DOM (cf.
   `docs/benchmarks/README.md`).
4. **Polling lifecycle propre** — `setInterval` enregistré avec
   `onScopeDispose` au lieu d'un `pollHandle` mutable + `onBeforeUnmount`
   séparé. Même pattern appliqué au `chartInstance.destroy()`.

Bump infrastructurel induit : ajout de `vue-virtual-scroller` aux
dépendances `frontend/package.json` (résolu à v3.x au build).

Aucun changement backend, aucun changement infra Nginx/PHP, aucun
changement aux autres pages.

---

## Tableau métriques avant/après

### Page `/organizer/dashboard` (12 events visibles, polling 10s)

| Métrique                        | Starter   | J2-dashboard | Δ           | Note |
|---------------------------------|----------:|-------------:|------------:|------|
| Lighthouse-like LCP load        | 698 ms    | 699 ms       | stable      | refonte non-load |
| Lighthouse-like CLS load        |  0.32     |  0.32        | stable      | chart canvas init |
| Longtasks > 50ms / 33s polling  |     0     |     0        | n/a         | page intrinsèquement light |
| TBT pendant polling             |  0 ms     |  0 ms        | n/a         | sub-50ms baseline |
| DOM `<tr>`                      |   13      |   13         | inchangé    | refonte purement comportementale |

**Lecture** : à 12 rows, le starter est déjà sous le seuil 50ms même
avec une réassignation totale + Chart.js update. Les gains de J2
sont **structurels** (le pattern scale à 50/200/1000 rows, le starter
dégénérerait) et **forward-compatibles** (`onScopeDispose` permet une
factorisation `usePolling()`, `shallowRef` évite la régression quand
l'API événements va inclure plus de champs).

### Page `/organizer/events/600/participants` (7000 tickets event star)

| Métrique                          | Starter      | J2-dashboard    | Δ              |
|-----------------------------------|-------------:|----------------:|---------------:|
| DOM `<tr>` total                  | **7 001**    |       0         | **−7 001**     |
| DOM `[data-row]` viewport         | 0            | **23**          | cible ~30 ✅   |
| DOM `[data-row]` peak scroll      | 0            | **30**          | cible ~30 ✅   |
| DOM nodes total                   | **42 062**   | **226**         | **−99.5 %** (186×) |
| Table HTML bytes                  | **2 005 039** (~2 Mo) | 17 522 (scroller) | **−99.1 %** (114×) |
| Longtasks pendant scroll 5s       | **85**       | **0**           | **−100 %**     |
| Longtask total pendant scroll 5s  | 13 822 ms    | 0 ms            | **−100 %**     |
| TBT pendant scroll 5s             | **9 572 ms** | **0 ms**        | **−100 %**     |
| Longtask max                      | **793 ms**   | **0 ms**        | **−100 %**     |
| FPS pendant scroll                |    25        |    **85**       | **+240 %**     |
| INP clic searchbox                | 104 ms       | **24 ms**       | **−77 %**      |

**Lecture** : c'est *là* que la branche tient ses promesses. Tous les
indicateurs de pathologie main-thread (longtasks, TBT, FPS, INP)
passent à zéro ou à leur cible théorique. Le DOM est divisé par 186×.
La table HTML qui pesait 2 Mo dans le DOM en pèse 17.5 Ko après.

---

## Cibles différentielles annoncées (cf. brief Phase 5 itération 3)

| Cible                                            | Quantification          | Atteint |
|--------------------------------------------------|------------------------|--------|
| INP table participants : saccadé → fluide        | qualitatif             | ✅ FPS 25 → 85, 0 longtask, INP 104 → 24 ms |
| DOM count participants : 7000+ → ~30             | >200× moins            | ✅ 7001 → 23 viewport / 30 peak (304× / 233×) |
| INP dashboard : −50 %                            | quantitatif            | n/a (déjà sous seuil AVANT — page light à 12 rows) |
| Performance Lighthouse dashboard : +5 pts        | quantitatif Lighthouse | non vérifiable automatiquement (page auth-required, lhci collect ne suit pas le Bearer token Sanctum cf. `docs/benchmarks/README.md` § "Pages auth-required — mesure manuelle"). LCP/CLS load stables → pas de raison structurelle de gain Lighthouse sur le **load** ; les gains Vue 3 sont sur les ticks et interactions. |

## Cibles **absolues** (§9 spec) — rappel : non atteignables ici

| Cible §9                  | Cible    | État sur cette branche | Bloqué par                                   |
|---------------------------|---------:|-----------------------|----------------------------------------------|
| INP < 200 ms (toute page) | < 200 ms | ✅ atteinte sur participants (24 ms < 200 ms) ; dashboard déjà OK | rien (gain composé j1+j3 garderait sous seuil) |
| LCP home < 1.5 s          | < 1.5 s  | ❌ non concerné (home pas touchée par J2-dashboard) | `solution/j1-cdn-cache` (TTFB) + `solution/j2-bundle` (image AVIF + fonts) |
| TTFB API `/api/v1/events` p95 < 200 ms | < 200 ms | ❌ non touché | `solution/j3-postgres` (index city ILIKE) |
| Tunnel achat médian < 500 ms | < 500 ms | ❌ non touché | `solution/j3-laravel` (queues Redis + SELECT FOR UPDATE SKIP LOCKED) |

---

## Décompte `@perf-debt` résolus

**3 marqueurs convertis en `@perf-fix:`** sur cette branche :

| Fichier                                                          | Marqueur AVANT                                            | APRÈS    |
|------------------------------------------------------------------|-----------------------------------------------------------|----------|
| `frontend/app/pages/organizer/dashboard.vue`                     | un seul `ref` global (KPIs + chart + events)              | ✅ fix    |
| `frontend/app/pages/organizer/dashboard.vue`                     | pas de v-memo sur les rows de la table d'événements       | ✅ fix    |
| `frontend/app/pages/organizer/events/[id]/participants.vue`      | rendu en `v-for` direct de TOUTES les lignes (5000+)      | ✅ fix    |

`@perf-debt` restants côté frontend (cf. `docs/architecture.md` §5) :
images full-size, Chart.js statique dans le layout organizer, Google
Fonts, pas de `routeRules`, pas de `<ClientOnly>` lazy hydration —
résolus en `solution/j2-bundle` et `solution/j1-cdn-cache`.

---

## Notes méthodologiques

- **Mesure load LCP/CLS dashboard inchangée** : la refonte est
  *comportementale* (state, lifecycle), pas *structurale* (DOM,
  ressources). Aucune raison de bouger le critical path du load.
- **CLS 0.32 résiduel** : provient du chart canvas qui s'initialise
  après le mount (`renderChart()` après `salesChart.value = ...`).
  Layout shift unique au load, hors scope J2-dashboard. Stable
  AVANT/APRÈS.
- **Programmatic clicks ne déclenchent pas d'INP "réel"** : les
  events synthétiques ne sont pas trackés par `event-timing` API.
  L'INP est mesuré via les **vrais clics CDP** (isTrusted=true) du
  MCP chrome-devtools. Pour le typing dans la searchbox (qui
  déclencherait un re-render des 7000 rows en starter), MCP n'expose
  pas de `type_text` chargé ; le **scroll programmatique** est
  utilisé comme proxy plus fidèle de la pathologie main-thread, et
  le résultat (TBT 9572 ms → 0 ms) couvre largement la signature
  attendue de l'INP catastrophique au filtrage.
- **FPS 85 > 60Hz** : le scroll programmatique appelle `sleep(16)`
  entre deux frames (≈ 60Hz), mais Chromium peut rendre des frames
  intermédiaires sur les windows libres. Lecture utile : ≥ 60 FPS
  signifie que le main thread n'est jamais bloqué assez longtemps
  pour rater une frame. AVANT, les 25 FPS reflètent les longtasks
  qui mangent 2-3 frames d'affilée.
