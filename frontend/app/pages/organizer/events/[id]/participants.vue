<script setup lang="ts">
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

const participants = ref<Participant[]>([])
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
      @perf-debt: rendu en `v-for` direct de TOUTES les lignes
      (jusqu'à 5000+). Pas de virtualisation, pas de pagination UI,
      pas de v-memo. Le DOM explose, INP > 500 ms quand on tape dans la
      recherche. C'est le travail de la branche solution/j2-dashboard
      (vue-virtual-scroller + cursor pagination + index Postgres en J3).
    -->
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left">
          <tr>
            <th class="px-4 py-2 font-medium text-slate-600">
              Code
            </th>
            <th class="px-4 py-2 font-medium text-slate-600">
              Nom
            </th>
            <th class="px-4 py-2 font-medium text-slate-600">
              Email
            </th>
            <th class="px-4 py-2 font-medium text-slate-600">
              Catégorie
            </th>
            <th class="px-4 py-2 font-medium text-slate-600">
              Statut
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in participants" :key="p.id" class="border-t border-slate-100">
            <td class="px-4 py-1 font-mono text-xs">
              {{ p.code_short }}
            </td>
            <td class="px-4 py-1">
              {{ p.holder_name }}
            </td>
            <td class="px-4 py-1 text-slate-500">
              {{ p.email }}
            </td>
            <td class="px-4 py-1 text-slate-500">
              {{ p.category }}
            </td>
            <td class="px-4 py-1">
              {{ p.status }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
