# Atelier J2 - Dashboard Vue 3 et virtualisation

> **Branche solution** : `solution/j2-dashboard`
> **Pré-requis** : avoir suivi les ateliers précédents OU connaître le
> contenu de `docs/architecture.md`.

> **Note méthodologique - cibles différentielles vs absolues**
> (cf. `docs/architecture.md` §6
> "Cibles différentielles vs cibles absolues").
>
> Cet atelier livre des **gains différentiels** - l'apport propre de
> J2-dashboard mesuré contre le starter, périmètre Vue 3 réactivité +
> virtualisation isolé. Les **cibles absolues** (LCP home < 1.5 s,
> INP < 200 ms, etc.) supposent que **toutes** les optimisations sont
> composées sur la branche `final`. Cas d'école sur cette branche : les
> métriques Lighthouse de **load** (LCP/CLS) du dashboard sont stables
> AVANT/APRÈS - non par échec de l'atelier, mais parce que la refonte
> est *comportementale* (state, lifecycle, virtualisation) et opère sur
> les **interactions** et les **ticks de polling**, pas sur le critical
> path du load. C'est exactement ce que la note méthodologique veut
> éviter de prendre pour un échec : la signature attendue est sur INP /
> TBT / FPS / DOM count, pas sur LCP load.

### Ce que cet atelier améliore (gains différentiels mesurés)

Mesures vs `main` (cf. `docs/benchmarks/j2-dashboard-comparison.md`,
mêmes conditions throttling Mobile / Slow 4G / 4× CPU) :

#### Page `/organizer/events/600/participants` (7000 tickets event star)

| Métrique                          | Starter      | J2-dashboard    | Δ              |
|-----------------------------------|-------------:|----------------:|---------------:|
| DOM `<tr>` total                  | **7 001**    |       0         | **−7 001**     |
| DOM `[data-row]` viewport         | 0            | **23**          | cible ~30 ✅   |
| DOM nodes total                   | **42 062**   | **226**         | **−99.5 %**    |
| Table HTML bytes                  | **2 005 039** (~2 Mo) | 17 522 | **−99.1 %** (114×)|
| Longtasks pendant scroll 5s       | **85**       | **0**           | **−100 %**     |
| TBT pendant scroll 5s             | **9 572 ms** | **0 ms**        | **−100 %**     |
| Longtask max                      | **793 ms**   | **0 ms**        | **−100 %**     |
| FPS pendant scroll                |    25        |    **85**       | **+240 %**     |
| INP clic searchbox                | 104 ms       | **24 ms**       | **−77 %**      |

#### Page `/organizer/dashboard` (12 events visibles, polling 10s)

À cette taille (12 rows + 60 events), le travail Vue par tick est déjà
sous le seuil 50 ms en starter - TBT 0 ms AVANT comme APRÈS. Les gains
sont **structurels** (le pattern scale à 50/200/1000 rows, le starter
dégénérerait) et **forward-compatibles** (`onScopeDispose` permet une
factorisation `usePolling()`, `shallowRef` évite la régression quand
l'API événements va inclure plus de champs en `solution/j3-laravel`).

L'atelier valide **4 leviers** : split du state monolithique en refs
ciblés + `shallowRef`, `v-memo` sur les rows mémorisables,
virtualisation de la grosse liste via `vue-virtual-scroller`, lifecycle
propre via `onScopeDispose`.

### Ce qui n'est PAS dans le périmètre

Les cibles suivantes restent **non touchées** sur cette branche
isolée, par construction du périmètre J2-dashboard (frontend Vue 3
uniquement, pas d'infra ni de backend) :

- **LCP home < 1.5 s** : J2-dashboard ne touche pas la home.
  Atteignable via `solution/j1-cdn-cache` (SWR Nitro) +
  `solution/j2-bundle` (image AVIF + fonts self-hostées).
- **TTFB API `/api/v1/events` p95 < 200 ms** : J2-dashboard ne touche
  pas le backend. Cible `solution/j3-postgres` (index sur la colonne
  `city` que `ILIKE` parcourt).
- **Tunnel achat médian < 500 ms** : pareil, hors scope. Cible
  `solution/j3-laravel` (queues Redis + `SELECT FOR UPDATE SKIP
  LOCKED`).
- **Pagination cursor sur `/organizer/events/{id}/participants`** : la
  virtualisation rend le chargement complet *acceptable* côté frontend
  (DOM divisé par 186×), mais la requête backend reste un `SELECT *`
  sans `cursor` qui fetch les 7000 lignes en un payload. La pagination
  cursor est l'objet de `solution/j3-laravel`. La virtualisation
  composée à la pagination cursor donnera le résultat optimal en
  branche `final`.

Ces gains additionnels arrivent par composition lors du merge des
solutions sur la branche `final`.

## 1. Objectif pédagogique

Réduire le **coût main thread des interactions et du polling**
côté Vue 3 sans toucher au backend ni au caching HTTP, qui sont
l'objet d'autres branches. Quatre leviers complémentaires :

1. **State éclaté + `shallowRef`** : remplacer le ref monolithique
   `dashboardState` par 3 refs ciblés (`stats`, `salesChart`,
   `events`), avec `shallowRef` sur les deux collections. Cible : à
   chaque tick de polling, seuls les fragments qui lisent vraiment
   chaque ref sont invalidés, et Vue ne pose pas de Proxy par item
   sur les arrays remplacés en bloc.
2. **`v-memo` sur la table d'événements** : `v-memo="[ev.id, ev.title,
   ev.city, ev.status]"` mémorise chaque `<tr>` sur les 4 champs
   réellement lus. Tant qu'aucun champ ne change, Vue saute le diff
   entier de la ligne. Cible : zéro travail Vue par tick de polling
   sur les rows inchangées.
3. **Virtualisation de la liste participants** via
   `vue-virtual-scroller` (`RecycleScroller`). Cible : DOM divisé par
   ~200×, scroll fluide, INP au filtrage qui reste sous 200 ms.
4. **`onScopeDispose` pour le polling lifecycle**. Cible : code plus
   idiomatique Vue 3 (pas de variable mutable `pollHandle` hors
   scope), forward-compatible avec une factorisation `usePolling()`
   en composable.

Résultat mesuré sur participants event 600 (cf.
`docs/benchmarks/j2-dashboard-comparison.md`) :

- **DOM nodes** : 42 062 → 226 (−99.5 %, 186×).
- **TBT pendant scroll 5s** : 9 572 ms → 0 ms (−100 %).
- **FPS pendant scroll** : 25 → 85 (+240 %).
- **INP clic searchbox** : 104 ms → 24 ms (−77 %).

## 2. Énoncé pas-à-pas (depuis `main`)

```bash
git checkout main
git pull
git checkout -b mon-atelier-j2-dashboard
```

### Étape 1 - Éclater le state monolithique du dashboard

Éditez `frontend/app/pages/organizer/dashboard.vue`. Cherchez le
marqueur `@perf-debt: un seul ref global qui contient TOUT l'état du
dashboard`. Remplacez :

```ts
interface DashboardState {
  stats: Stats | null
  salesChart: SalesPoint[]
  events: Event[]
}
const dashboardState = ref<DashboardState>({
  stats: null, salesChart: [], events: [],
})

// ... dans loadAll() :
dashboardState.value = { stats: ..., salesChart: ..., events: ... }
```

par :

```ts
const stats = ref<Stats | null>(null)
const salesChart = shallowRef<SalesPoint[]>([])
const events = shallowRef<Event[]>([])

// ... dans loadAll() :
stats.value = statsResp.data
salesChart.value = chartResp.data
events.value = eventsResp.data
```

Mettez à jour le template (`dashboardState.stats.X` → `stats.X`,
`dashboardState.events` → `events`) et le `watch` du chart
(`watch(() => dashboardState.value.salesChart, ...)` → `watch(salesChart, ...)`).

**Pourquoi `shallowRef` et pas `ref` ?** `loadAll()` remplace
l'**array entier** à chaque tick (`events.value = eventsResp.data`).
Pas de mutation par item (`events.value.push(...)`, `events.value[i].x
= ...`). `shallowRef` réagit à la **réassignation** de `.value` mais
ne pose pas de Proxy sur chaque item - ce qui économise des dizaines
de millisecondes au mount sur les grosses listes.

Convertissez le commentaire `@perf-debt:` en `@perf-fix:` (et précisez
*comment* c'est fixé).

### Étape 2 - Ajouter `v-memo` sur les rows de la table d'événements

Toujours dans `dashboard.vue`. Cherchez le marqueur `@perf-debt: pas de
v-memo sur les rows`. Remplacez le `<tr v-for="ev in events.slice(0, 12)" :key="ev.id">`
par :

```vue
<tr
  v-for="ev in events.slice(0, 12)"
  :key="ev.id"
  v-memo="[ev.id, ev.title, ev.city, ev.status]"
  class="border-t border-slate-100"
>
```

**Pourquoi ces 4 champs ?** Convention Vue : la liste passée à `v-memo`
doit contenir **toutes les valeurs réactives lues** par le row
template. Lus dans le row : `ev.title`, `ev.city`, `ev.status`, et
indirectement `ev.id` (pour l'href `Gérer`). Si on en oublie un, le
row pourrait afficher une donnée stale. Si on en ajoute un, on
restreint inutilement la mémoization.

> **Note pédagogique** : `tickets_sold` n'est *pas* exposé par l'API
> events organizer du starter, donc on ne peut pas l'inclure ici. La
> fiche `EventResource` côté Laravel ne le matérialise qu'en
> `solution/j3-laravel` (cache Redis sur les KPIs aggregate). Sur
> `final`, la clé v-memo deviendra `[ev.id, ev.title, ev.city,
> ev.status, ev.tickets_sold]` - n'oubliez pas de l'ajuster lors du
> merge.

Convertissez le `@perf-debt:` en `@perf-fix:`.

### Étape 3 - Virtualiser la table participants

Installez la dépendance :

```bash
# Édite frontend/package.json (le rebuild régénère node_modules)
# Ajouter dans "dependencies":  "vue-virtual-scroller": "latest"

make frontend-rebuild
```

Éditez `frontend/app/pages/organizer/events/[id]/participants.vue`.

Au top du `<script setup>` :

```ts
import { RecycleScroller } from 'vue-virtual-scroller'
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css'
```

Passez `participants` en `shallowRef` (même justification qu'à
l'étape 1) :

```ts
const participants = shallowRef<Participant[]>([])
```

Cherchez le marqueur `@perf-debt: rendu en v-for direct de TOUTES les
lignes`. Remplacez la `<table>` par une grille CSS et un
`RecycleScroller` :

```vue
<div class="rounded-lg border border-slate-200 bg-white overflow-hidden">
  <!-- En-tête : grille à 5 colonnes alignées avec les rows. -->
  <div role="row" class="participants-grid bg-slate-50 ...">
    <div role="columnheader" class="px-4 py-2">Code</div>
    <div role="columnheader" class="px-4 py-2">Nom</div>
    <div role="columnheader" class="px-4 py-2">Email</div>
    <div role="columnheader" class="px-4 py-2">Catégorie</div>
    <div role="columnheader" class="px-4 py-2">Statut</div>
  </div>

  <!-- Corps virtualisé. ClientOnly évite l'instanciation SSR de
       RecycleScroller (qui dépend de ResizeObserver / window). -->
  <ClientOnly>
    <RecycleScroller
      v-if="participants.length"
      class="participants-scroller"
      :items="participants"
      :item-size="32"
      key-field="id"
      :buffer="200"
    >
      <template #default="{ item }">
        <div
          data-row
          role="row"
          class="participants-grid border-t border-slate-100 text-sm"
        >
          <div role="cell" class="px-4 py-1 font-mono text-xs truncate">
            {{ item.code_short }}
          </div>
          <div role="cell" class="px-4 py-1 truncate">{{ item.holder_name }}</div>
          <div role="cell" class="px-4 py-1 text-slate-500 truncate">{{ item.email }}</div>
          <div role="cell" class="px-4 py-1 text-slate-500 truncate">{{ item.category }}</div>
          <div role="cell" class="px-4 py-1">{{ item.status }}</div>
        </div>
      </template>
    </RecycleScroller>
  </ClientOnly>
</div>
```

Et le `<style scoped>` :

```css
.participants-grid {
  display: grid;
  grid-template-columns: 140px minmax(140px, 1fr) minmax(180px, 1.5fr) 130px 90px;
  align-items: center;
}

.participants-scroller {
  height: calc(100vh - 320px);
  min-height: 480px;
}
```

**Pourquoi pas `<table>` ?** `RecycleScroller` positionne ses items
en absolu dans un conteneur dimensionné. Le browser CSS engine ne
laisse pas un `<tbody>` accueillir des enfants en `position:
absolute` proprement. La grille CSS donne le même rendu visuel (5
colonnes alignées entre header et rows) sans la contrainte sémantique
table.

**Pourquoi `data-row` ?** Convention de mesure documentée dans
`docs/benchmarks/README.md` §4. Selector neutre vis-à-vis du markup :
`document.querySelectorAll('[data-row]').length` donne le nombre de
rows réellement matérialisées dans le DOM (~25-30 pour ce scroller),
là où `tr.length` retournerait 0 (faux négatif).

**Pourquoi `<ClientOnly>` ?** `RecycleScroller` instancie un
`ResizeObserver` au mount qui n'existe pas en environnement Node SSR.
Comme la page est de toute façon en CSR (les participants sont
fetchés en `onMounted` après hydratation, cf. mémoire `feedback_nuxt_ssr_auth`),
on évite simplement le rendu SSR du scroller. Le `v-if="participants.length"`
en plus s'assure qu'on ne mount le scroller qu'une fois les données
disponibles (sinon RecycleScroller émet des avertissements sur un
`items=[]`).

Convertissez le `@perf-debt:` en `@perf-fix:`.

### Étape 4 - Polling lifecycle propre via `onScopeDispose`

Dans `dashboard.vue`. Remplacez :

```ts
let pollHandle: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  loadAll()
  pollHandle = setInterval(loadAll, 10_000)
})
onBeforeUnmount(() => {
  if (pollHandle) clearInterval(pollHandle)
})
```

par :

```ts
onMounted(() => {
  loadAll()
  const id = setInterval(loadAll, 10_000)
  onScopeDispose(() => clearInterval(id))
})
```

Même chose pour le `chartInstance.destroy()` :

```ts
onMounted(() => {
  renderChart()
  onScopeDispose(() => chartInstance?.destroy())
})
```

**Pourquoi `onScopeDispose` plutôt que `onBeforeUnmount` ?**
- L'`id` du timer est capturé par closure : plus besoin d'une variable
  mutable hors-scope.
- `onScopeDispose` se rattache au **effect scope** courant, pas au
  cycle de vie du composant directement. C'est forward-compatible : si
  on factorise plus tard cette logique dans un composable
  `usePolling(loadAll, 10_000)`, le cleanup se rebindera
  automatiquement au scope du callsite - `onBeforeUnmount` ne
  fonctionnerait que dans le composant racine.

Cf. la doc Vue : <https://vuejs.org/api/reactivity-advanced.html#onscopedispose>.

## 3. Vérifications

```bash
# 1. Le code compile.
make frontend-typecheck

# 2. Le frontend rebuild ok et démarre.
make frontend-rebuild

# 3. Mesure visuelle :
#    - http://localhost:8081/login → organizer@demo.test / password
#    - http://localhost:8081/organizer/dashboard
#       Le polling 10s ne crée plus de jank visible sur le chart.
#    - http://localhost:8081/organizer/events/600/participants
#       7 000 lignes annoncées. Scroll fluide. Filtre / recherche
#       réactifs.

# 4. Mesure DOM count (DevTools console, sur participants) :
document.querySelectorAll('[data-row]').length
# Attendu : ~25-30 (ne change pas en scrollant).

# 5. Mesure INP / TBT (DevTools Performance, scroll de la liste) :
#    longtasks ~ 0, TBT proche 0, FPS proche 60.
```

Si `make frontend-typecheck` échoue sur `vue-virtual-scroller` non
trouvé, c'est que `make frontend-rebuild` n'a pas encore tourné depuis
l'ajout dans `package.json`. Lancez-le.

## 4. Convention `data-row`

Toute ligne d'une liste virtualisée porte `data-row`. La mesure DOM
count se fait via :

```js
document.querySelectorAll('[data-row]').length
```

Le selector est neutre vis-à-vis du markup (table → grid → list),
pour rester valide au passage `<table>` → `<div role="row">`.

Cf. `docs/benchmarks/README.md` §4 pour le détail.

## 5. Pour aller plus loin (en branche `final`)

- **Pagination cursor backend** sur
  `/organizer/events/{id}/participants` (`solution/j3-laravel`) —
  réduit le payload réseau initial de ~2 Mo à ~50 Ko, mais la
  virtualisation reste utile pour le scroll infini.
- **`tickets_sold` exposé** sur l'API `/organizer/events`
  (`solution/j3-laravel`) - permet de l'intégrer à la clé v-memo
  pour tracker le changement de remplissage en temps réel.
- **Composable `usePolling(fn, interval)`** factorisable en branche
  `final` pour réutiliser le pattern sur d'autres pages organizer.
- **Index Postgres** sur `tickets(event_session_id, created_at desc)`
  (`solution/j3-postgres`) - accélère le SQL qui populate la table
  participants côté backend (de plusieurs centaines de ms à dizaines
  de ms).

Ces gains se composent sur `final` sans conflit avec le périmètre
J2-dashboard.
