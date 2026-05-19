<script setup lang="ts">
import type { Event, SingleResponse } from '~/types/event'
import { formatPrice } from '~/utils/format'

// @perf-fix: Chart.js code-splitté via `defineAsyncComponent`. SalesChart
// (~ 160 Ko raw / ~ 56 Ko gzipped quand inliné) n'est tiré que sur le
// dashboard, et **après** mount client (puisque tout l'espace organizer
// est sous <ClientOnly> dans le layout). — solution/j2-bundle.
const SalesChart = defineAsyncComponent({
  loader: () => import('~/components/SalesChart.vue'),
  loadingComponent: () => h('div', {
    class: 'h-64 grid place-items-center text-sm text-slate-400',
  }, 'Chargement de la courbe…'),
})

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

// @perf-debt: un seul `ref` global qui contient TOUT l'état du dashboard
// (KPIs, courbe de ventes, table d'events). Chaque tick de polling
// remplace l'objet entier → invalidation Vue de l'arbre complet, perf
// catastrophique avec polling 10s. Résolu en J2 atelier "j2-dashboard"
// (refs ciblés + shallowRef + v-memo).
interface DashboardState {
  stats: Stats | null
  salesChart: SalesPoint[]
  events: Event[]
}
const dashboardState = ref<DashboardState>({
  stats: null,
  salesChart: [],
  events: [],
})

const loading = ref(true)
const error = ref<string | null>(null)

async function loadAll() {
  try {
    const [statsResp, chartResp, eventsResp] = await Promise.all([
      api<SingleResponse<Stats>>('/organizer/stats'),
      api<{ data: SalesPoint[] }>('/organizer/sales-chart'),
      api<{ data: Event[] }>('/organizer/events'),
    ])
    // @perf-debt: réassignation complète → re-render total Vue.
    dashboardState.value = {
      stats: statsResp.data,
      salesChart: chartResp.data,
      events: eventsResp.data,
    }
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
// CSR — `dashboardState` est dans son état initial vide ; les deux rendus
// matchent donc et il n'y a pas de mismatch d'hydratation. Le contenu réel
// arrive après le mount, quand `plugins/auth.client.ts` a peuplé Pinia
// avec le Bearer token.

// Polling 10s (cf. spec §5 écran 5).
let pollHandle: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  loadAll()
  pollHandle = setInterval(loadAll, 10_000)
})
onBeforeUnmount(() => {
  if (pollHandle) clearInterval(pollHandle)
})

// La logique Chart.js (init, update, destroy) vit désormais dans
// `~/components/SalesChart.vue`. Cette page passe juste les `points` ;
// le composant gère canvas + lifecycle Chart.js.
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
    <div v-if="dashboardState.stats" class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 mb-8">
      <div class="rounded-lg bg-white p-5 border border-slate-200">
        <p class="text-xs uppercase text-slate-500 mb-1">
          Ventes aujourd'hui
        </p>
        <p class="text-2xl font-bold">
          {{ dashboardState.stats.today_orders.toLocaleString('fr-FR') }}
        </p>
      </div>
      <div class="rounded-lg bg-white p-5 border border-slate-200">
        <p class="text-xs uppercase text-slate-500 mb-1">
          Revenus du mois
        </p>
        <p class="text-2xl font-bold">
          {{ formatPrice(dashboardState.stats.month_revenue_cents) }}
        </p>
      </div>
      <div class="rounded-lg bg-white p-5 border border-slate-200">
        <p class="text-xs uppercase text-slate-500 mb-1">
          Taux de remplissage
        </p>
        <p class="text-2xl font-bold">
          {{ Math.round(dashboardState.stats.fill_rate * 100) }} %
        </p>
      </div>
      <div class="rounded-lg bg-white p-5 border border-slate-200">
        <p class="text-xs uppercase text-slate-500 mb-1">
          Événements actifs
        </p>
        <p class="text-2xl font-bold">
          {{ dashboardState.stats.active_events }}
        </p>
      </div>
    </div>

    <!-- Courbe de ventes — composant async, voir defineAsyncComponent ci-dessus. -->
    <section class="rounded-lg bg-white p-5 border border-slate-200 mb-8">
      <h2 class="text-lg font-semibold mb-3">
        Ventes — 30 derniers jours
      </h2>
      <SalesChart :points="dashboardState.salesChart" />
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
          <!-- @perf-debt: pas de v-memo sur les rows — chaque tick de
               polling re-render TOUTES les lignes (même quand rien n'a
               changé). Résolu en J2 atelier "j2-dashboard". -->
          <tr
            v-for="ev in dashboardState.events.slice(0, 12)"
            :key="ev.id"
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
