<script setup lang="ts">
import { RecycleScroller } from 'vue-virtual-scroller'
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css'

definePageMeta({
  layout: 'organizer',
  middleware: ['auth', 'organizer'],
})

const route = useRoute()
const api = useApi()

const eventId = computed(() => route.params.id as string)

interface Participant {
  id: number
  code_short: string
  holder_name: string
  email: string
  category: string
  status: 'valid' | 'cancelled' | 'used'
}

const search = ref('')
const categoryFilter = ref('')

// @perf-fix: shallowRef sur la collection — on remplace le tableau
// entier dans `fetchAll()`, pas de mutation par item. Évite le coût
// de réactivité profonde Vue (Proxy par item × 7000 = ~50 ms de mount
// gratuit avant même le rendu DOM).
const participants = shallowRef<Participant[]>([])
const pending = ref(false)
const error = ref<string | null>(null)

useHead({ title: 'Participants · Resonance' })

// L'endpoint /organizer/events/{id}/participants ne paginait plus :
// il renvoie l'ensemble des participants de l'event en une réponse plate
// `{ data: Participant[] }` (cf. spec §8 — le contrat starter exige un
// v-for non virtualisé sur 5 000+ lignes côté front).
async function fetchAll() {
  pending.value = true
  error.value = null
  try {
    const resp = await api<{ data: Participant[] }>(
      `/organizer/events/${eventId.value}/participants`,
      {
        query: {
          q: search.value || undefined,
          category: categoryFilter.value || undefined,
        },
      },
    )
    participants.value = resp.data
  }
  catch (e) {
    error.value = (e as Error).message ?? 'Erreur de chargement'
  }
  finally {
    pending.value = false
  }
}

// Équivalent du `{ server: false }` de useAsyncData : déclenchement initial
// dans `onMounted` (côté client uniquement) pour éviter un fetch SSR sans
// Bearer token et garantir l'égalité SSR/CSR-initial-render.
onMounted(() => { fetchAll() })

let searchTimer: ReturnType<typeof setTimeout> | null = null
watch([search, categoryFilter], () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => fetchAll(), 250)
})

function exportCsv() {
  const rows = ['code,holder_name,email,category,status']
  for (const p of participants.value) {
    rows.push([p.code_short, p.holder_name, p.email, p.category, p.status].join(','))
  }
  const blob = new Blob([rows.join('\n')], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `participants-event-${eventId.value}.csv`
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-8">
    <NuxtLink to="/organizer/events" class="text-sm text-slate-500 hover:underline">
      ← Mes événements
    </NuxtLink>

    <header class="flex items-center justify-between mt-2 mb-6">
      <h1 class="text-2xl font-bold">
        Participants — événement #{{ eventId }}
      </h1>
      <button
        :disabled="!participants.length"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 disabled:opacity-50"
        @click="exportCsv"
      >
        Exporter CSV
      </button>
    </header>

    <!-- Toolbar -->
    <div class="flex flex-wrap gap-3 mb-4">
      <input
        v-model="search"
        type="search"
        placeholder="Rechercher (nom / email)"
        class="rounded-md border border-slate-300 px-3 py-2 text-sm w-72"
      >
      <select v-model="categoryFilter" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
        <option value="">
          Toutes catégories
        </option>
        <option value="Carré Or">
          Carré Or
        </option>
        <option value="Catégorie 1">
          Catégorie 1
        </option>
        <option value="Catégorie 2">
          Catégorie 2
        </option>
      </select>
    </div>

    <p v-if="error" class="text-red-600 mb-3">
      {{ error }}
    </p>

    <p class="text-sm text-slate-500 mb-2">
      {{ pending ? 'Chargement…' : `${participants.length.toLocaleString('fr-FR')} ligne${participants.length > 1 ? 's' : ''}` }}
    </p>

    <!--
      @perf-fix: virtualisation via vue-virtual-scroller.
      RecycleScroller ne monte qu'une vingtaine de rows (viewport +
      buffer), recyclées au scroll. La structure <table>/<tr> est
      remplacée par une grille CSS (5 colonnes fixes) car v-virtual-scroller
      gère le positionnement absolu de ses items et n'est pas compatible
      avec la sémantique <tbody>. Convention de comptage DOM : chaque
      ligne porte `data-row`, le selector `[data-row]` mesure le nombre
      de rows réellement dans le DOM (cf. `docs/benchmarks/README.md`).
    -->
    <div class="rounded-lg border border-slate-200 bg-white overflow-hidden">
      <!-- En-tête en grille pour aligner les colonnes du body virtualisé. -->
      <div
        role="row"
        class="participants-grid bg-slate-50 text-left text-sm font-medium text-slate-600"
      >
        <div role="columnheader" class="px-4 py-2">
          Code
        </div>
        <div role="columnheader" class="px-4 py-2">
          Nom
        </div>
        <div role="columnheader" class="px-4 py-2">
          Email
        </div>
        <div role="columnheader" class="px-4 py-2">
          Catégorie
        </div>
        <div role="columnheader" class="px-4 py-2">
          Statut
        </div>
      </div>

      <!-- Corps virtualisé. ClientOnly évite l'instanciation SSR
           (RecycleScroller s'appuie sur ResizeObserver / window). -->
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
              <div role="cell" class="px-4 py-1 truncate">
                {{ item.holder_name }}
              </div>
              <div role="cell" class="px-4 py-1 text-slate-500 truncate">
                {{ item.email }}
              </div>
              <div role="cell" class="px-4 py-1 text-slate-500 truncate">
                {{ item.category }}
              </div>
              <div role="cell" class="px-4 py-1">
                {{ item.status }}
              </div>
            </div>
          </template>
        </RecycleScroller>
      </ClientOnly>
    </div>
  </div>
</template>

<style scoped>
.participants-grid {
  display: grid;
  grid-template-columns: 140px minmax(140px, 1fr) minmax(180px, 1.5fr) 130px 90px;
  align-items: center;
}

.participants-scroller {
  height: calc(100vh - 320px);
  min-height: 480px;
}
</style>
