<script setup lang="ts">
import type { CursorPaginatedResponse, Event, EventCategory } from '~/types/event'
import EventCard from '~/components/EventCard.vue'
import { categoryLabel } from '~/utils/format'

definePageMeta({ layout: 'default' })
useHead({ title: 'Recherche d\'événements · Resonance' })

const route = useRoute()
const router = useRouter()
const api = useApi()

const CATEGORIES: EventCategory[] = [
  'concert', 'festival', 'theater', 'conference', 'exhibition',
]
const FR_CITIES = [
  'Paris', 'Lyon', 'Marseille', 'Bordeaux', 'Lille', 'Nantes',
  'Toulouse', 'Strasbourg', 'Nice', 'Montpellier',
]

// Filtres synchronisés avec la query string.
const q = ref((route.query.q as string) ?? '')
const selectedCategories = ref<string[]>(
  Array.isArray(route.query.category)
    ? (route.query.category as string[])
    : route.query.category ? [route.query.category as string] : [],
)
const selectedCities = ref<string[]>(
  Array.isArray(route.query.city)
    ? (route.query.city as string[])
    : route.query.city ? [route.query.city as string] : [],
)
const sort = ref((route.query.sort as string) ?? 'date')

const events = ref<Event[]>([])
const nextCursor = ref<string | null>(null)
const perPage = ref(20)
const pending = ref(false)
const loadingMore = ref(false)
const error = ref<string | null>(null)

function buildQuery(cursor: string | null = null) {
  return {
    q: q.value || undefined,
    category: selectedCategories.value.length ? selectedCategories.value : undefined,
    city: selectedCities.value.length ? selectedCities.value : undefined,
    sort: sort.value,
    cursor: cursor ?? undefined,
  }
}

// @perf-fix: pagination cursor (per_page=20). On reset events sur change
// de filtres, on append sur "Charger plus" (cf. resonance-spec.md §5
// écran 2 — bouton qui appelle ?cursor=xxx).
async function fetchFirstPage() {
  pending.value = true
  error.value = null
  try {
    const data = await api<CursorPaginatedResponse<Event>>('/events', {
      query: buildQuery(),
    })
    events.value = data.data
    nextCursor.value = data.meta?.next_cursor ?? null
    perPage.value = data.meta?.per_page ?? 20
  }
  catch {
    error.value = 'Impossible de charger les résultats.'
  }
  finally {
    pending.value = false
  }
}

async function loadMore() {
  if (!nextCursor.value || loadingMore.value) return
  loadingMore.value = true
  try {
    const data = await api<CursorPaginatedResponse<Event>>('/events', {
      query: buildQuery(nextCursor.value),
    })
    events.value = [...events.value, ...data.data]
    nextCursor.value = data.meta?.next_cursor ?? null
  }
  catch {
    error.value = 'Impossible de charger la page suivante.'
  }
  finally {
    loadingMore.value = false
  }
}

// Resync URL ↔ état interne quand l'utilisateur change un filtre.
function applyFilters() {
  router.replace({
    path: '/events',
    query: {
      q: q.value || undefined,
      category: selectedCategories.value.length ? selectedCategories.value : undefined,
      city: selectedCities.value.length ? selectedCities.value : undefined,
      sort: sort.value,
    },
  })
  fetchFirstPage()
}

await fetchFirstPage()

watch(() => route.query, () => {
  q.value = (route.query.q as string) ?? ''
  selectedCategories.value = Array.isArray(route.query.category)
    ? (route.query.category as string[])
    : route.query.category ? [route.query.category as string] : []
  selectedCities.value = Array.isArray(route.query.city)
    ? (route.query.city as string[])
    : route.query.city ? [route.query.city as string] : []
  sort.value = (route.query.sort as string) ?? 'date'
  fetchFirstPage()
})
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-8 grid gap-8 lg:grid-cols-[260px_1fr]">
    <!-- ====== Sidebar filtres ====== -->
    <aside class="space-y-6">
      <h2 class="text-lg font-semibold">
        Filtres
      </h2>

      <div>
        <label class="text-sm font-medium text-slate-700 mb-2 block">Mot-clé</label>
        <input
          v-model="q"
          type="search"
          placeholder="Titre, lieu…"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          @keyup.enter="applyFilters"
        >
      </div>

      <div>
        <p class="text-sm font-medium text-slate-700 mb-2">
          Catégorie
        </p>
        <div class="space-y-1">
          <label v-for="cat in CATEGORIES" :key="cat" class="flex items-center text-sm">
            <input
              v-model="selectedCategories"
              type="checkbox"
              :value="cat"
              class="mr-2"
              @change="applyFilters"
            >
            {{ categoryLabel(cat) }}
          </label>
        </div>
      </div>

      <div>
        <p class="text-sm font-medium text-slate-700 mb-2">
          Ville
        </p>
        <div class="space-y-1 max-h-48 overflow-y-auto pr-2">
          <label v-for="city in FR_CITIES" :key="city" class="flex items-center text-sm">
            <input
              v-model="selectedCities"
              type="checkbox"
              :value="city"
              class="mr-2"
              @change="applyFilters"
            >
            {{ city }}
          </label>
        </div>
      </div>

      <div>
        <label class="text-sm font-medium text-slate-700 mb-2 block">Tri</label>
        <select
          v-model="sort"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          @change="applyFilters"
        >
          <option value="date">
            Date
          </option>
          <option value="price">
            Prix
          </option>
          <option value="popularity">
            Popularité
          </option>
        </select>
      </div>
    </aside>

    <!-- ====== Résultats ====== -->
    <section>
      <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">
          {{ events.length }} événement<span v-if="events.length > 1">s</span>
          <span v-if="nextCursor" class="text-sm font-normal text-slate-500">(page {{ Math.ceil(events.length / perPage) }})</span>
        </h1>
      </div>

      <p v-if="error" class="text-red-600">
        {{ error }}
      </p>

      <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
        <EventCard v-for="ev in events" :key="ev.id" :event="ev" />
      </div>

      <p v-if="pending" class="mt-8 text-center text-slate-500 text-sm">
        Chargement…
      </p>

      <div v-if="!pending && nextCursor" class="mt-8 flex justify-center">
        <button
          type="button"
          class="rounded-md bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white px-6 py-2 text-sm font-medium"
          :disabled="loadingMore"
          @click="loadMore"
        >
          {{ loadingMore ? 'Chargement…' : 'Charger plus' }}
        </button>
      </div>
    </section>
  </div>
</template>
