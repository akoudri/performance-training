<script setup lang="ts">
import type { Event, SingleResponse } from '~/types/event'
import { Chart } from 'chart.js'
import { formatPrice } from '~/utils/format'

definePageMeta({
  layout: 'organizer',
  middleware: ['auth', 'organizer'],
})
useHead({ title: 'Dashboard · Resonance' })

const api = useApi()
const auth = useAuthStore()

interface Stats {
  today_orders: number
  month_revenue_cents: number
  fill_rate: number
  active_events: number
}
interface SalesPoint {
  day: string
  orders: number
  revenue_cents: number
}

// @perf-fix: state éclaté en refs ciblés au lieu d'un seul ref global.
// Chaque tick de polling met à jour les refs indépendamment, ce qui
// limite l'invalidation Vue aux fragments du template qui lisent
// vraiment chaque ref. `salesChart` et `events` sont en `shallowRef`
// car on remplace l'array entier (pas de mutation interne) — Vue ne
// rend pas ces tableaux profondément réactifs (pas de Proxy par item).
const stats = ref<Stats | null>(null)
const salesChart = shallowRef<SalesPoint[]>([])
const events = shallowRef<Event[]>([])

const loading = ref(true)
const error = ref<string | null>(null)

async function loadAll() {
  try {
    const [statsResp, chartResp, eventsResp] = await Promise.all([
      api<SingleResponse<Stats>>('/organizer/stats'),
      api<{ data: SalesPoint[] }>('/organizer/sales-chart'),
      api<{ data: Event[] }>('/organizer/events'),
    ])
    // @perf-fix: trois assignations indépendantes → seuls les fragments
    // qui lisent chaque ref sont invalidés, pas l'arbre complet.
    stats.value = statsResp.data
    salesChart.value = chartResp.data
    events.value = eventsResp.data
  }
  catch (e) {
    error.value = (e as Error).message ?? 'Erreur de chargement.'
  }
  finally {
    loading.value = false
  }
}

// Équivalent du `{ server: false }` de useAsyncData : on déclenche le fetch
// dans `onMounted` (côté client uniquement). Au render initial — SSR comme
// CSR — `stats`, `salesChart`, `events` sont dans leur état initial vide ;
// les deux rendus matchent donc et il n'y a pas de mismatch d'hydratation.
// Le contenu réel arrive après le mount, quand `plugins/auth.client.ts` a
// peuplé Pinia avec le Bearer token.

// @perf-fix: polling 10s (cf. spec §5 écran 5) avec cleanup attaché au
// scope du composant via `onScopeDispose`. Plus besoin d'une variable
// mutable hors-scope pour stocker le handle ni d'un `onBeforeUnmount`
// séparé : le timer est disposé automatiquement au unmount, et tout
// effet futur (useXxx composable, watchEffect imbriqué) hériterait du
// même scope sans rebrancher manuellement le cleanup.
onMounted(() => {
  loadAll()
  const id = setInterval(loadAll, 10_000)
  onScopeDispose(() => clearInterval(id))
})

// ---- Chart.js : courbe ventes 30j --------------------------------------
const chartCanvas = ref<HTMLCanvasElement | null>(null)
let chartInstance: Chart | null = null

function renderChart() {
  if (!chartCanvas.value) return
  const points = salesChart.value
  if (chartInstance) {
    chartInstance.data.labels = points.map(p => p.day.slice(0, 10))
    chartInstance.data.datasets[0]!.data = points.map(p => p.revenue_cents / 100)
    chartInstance.update('none')
    return
  }
  chartInstance = new Chart(chartCanvas.value, {
    type: 'line',
    data: {
      labels: points.map(p => p.day.slice(0, 10)),
      datasets: [{
        label: 'Revenus (€)',
        data: points.map(p => p.revenue_cents / 100),
        borderColor: '#4655e8',
        backgroundColor: 'rgba(70, 85, 232, 0.15)',
        fill: true,
        tension: 0.25,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } },
    },
  })
}

watch(salesChart, () => renderChart(), { immediate: false })
onMounted(() => {
  renderChart()
  onScopeDispose(() => chartInstance?.destroy())
})
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-8">
    <h1 class="text-2xl font-bold mb-1">
      Bonjour, {{ auth.user?.name ?? 'organisateur' }}
    </h1>
    <p class="text-slate-500 mb-8 text-sm">
      Vue d'ensemble de votre activité (refresh toutes les 10s).
    </p>

    <p v-if="error" class="text-red-600 mb-4">
      {{ error }}
    </p>

    <!-- KPIs -->
    <div v-if="stats" class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 mb-8">
      <div class="rounded-lg bg-white p-5 border border-slate-200">
        <p class="text-xs uppercase text-slate-500 mb-1">
          Ventes aujourd'hui
        </p>
        <p class="text-2xl font-bold">
          {{ stats.today_orders.toLocaleString('fr-FR') }}
        </p>
      </div>
      <div class="rounded-lg bg-white p-5 border border-slate-200">
        <p class="text-xs uppercase text-slate-500 mb-1">
          Revenus du mois
        </p>
        <p class="text-2xl font-bold">
          {{ formatPrice(stats.month_revenue_cents) }}
        </p>
      </div>
      <div class="rounded-lg bg-white p-5 border border-slate-200">
        <p class="text-xs uppercase text-slate-500 mb-1">
          Taux de remplissage
        </p>
        <p class="text-2xl font-bold">
          {{ Math.round(stats.fill_rate * 100) }} %
        </p>
      </div>
      <div class="rounded-lg bg-white p-5 border border-slate-200">
        <p class="text-xs uppercase text-slate-500 mb-1">
          Événements actifs
        </p>
        <p class="text-2xl font-bold">
          {{ stats.active_events }}
        </p>
      </div>
    </div>

    <!-- Courbe de ventes -->
    <section class="rounded-lg bg-white p-5 border border-slate-200 mb-8">
      <h2 class="text-lg font-semibold mb-3">
        Ventes — 30 derniers jours
      </h2>
      <div class="h-64">
        <canvas ref="chartCanvas" />
      </div>
    </section>

    <!-- Table d'événements -->
    <section class="rounded-lg bg-white border border-slate-200 overflow-hidden">
      <header class="flex items-center justify-between p-5 border-b border-slate-200">
        <h2 class="text-lg font-semibold">
          Mes événements
        </h2>
        <NuxtLink
          to="/organizer/events"
          class="text-sm text-brand-700 hover:underline"
        >
          Voir tout →
        </NuxtLink>
      </header>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left">
          <tr>
            <th class="px-5 py-2 font-medium text-slate-600">
              Titre
            </th>
            <th class="px-5 py-2 font-medium text-slate-600">
              Ville
            </th>
            <th class="px-5 py-2 font-medium text-slate-600">
              Statut
            </th>
            <th class="px-5 py-2 font-medium text-slate-600">
              Action
            </th>
          </tr>
        </thead>
        <tbody>
          <!-- @perf-fix: v-memo sur les champs réellement lus par le row.
               Tant que ces 4 valeurs sont identiques d'un tick de polling
               au suivant, Vue saute entièrement le diff de ce <tr> et
               de ses enfants — coût marginal du polling sur la table
               réduit à zéro pour les lignes inchangées. -->
          <tr
            v-for="ev in events.slice(0, 12)"
            :key="ev.id"
            v-memo="[ev.id, ev.title, ev.city, ev.status]"
            class="border-t border-slate-100"
          >
            <td class="px-5 py-2">
              {{ ev.title }}
            </td>
            <td class="px-5 py-2 text-slate-500">
              {{ ev.city }}
            </td>
            <td class="px-5 py-2 text-slate-500">
              {{ ev.status }}
            </td>
            <td class="px-5 py-2">
              <NuxtLink
                :to="`/organizer/events/${ev.id}/edit`"
                class="text-brand-700 hover:underline"
              >
                Gérer
              </NuxtLink>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
