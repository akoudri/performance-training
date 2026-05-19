<script setup lang="ts">
/**
 * Carte de billetterie : sélecteur session + sélecteur catégories +
 * total + bouton "Acheter". Composant lourd (state local, watchers,
 * formules de prix) — extrait de pages/events/[slug].vue pour permettre
 * la lazy hydration côté page (`<LazyTicketSelector hydrate-on-idle>`).
 *
 * SSR rend le HTML complet (pas de mismatch d'hydratation), mais la
 * mise en place des event handlers et des watchers attend un createur
 * (idle / interaction / visible) — ce qui débloque le LCP du hero.
 *
 * @perf-fix: lazy hydration TicketSelector — solution/j2-bundle.
 */
import type { Event, EventSession } from '~/types/event'
import { formatDateTime, formatPrice } from '~/utils/format'

const props = defineProps<{
  event: Event
  sessions: EventSession[]
}>()

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const selectedSessionId = ref<number | null>(null)
const quantities = ref<Record<number, number>>({})

const selectedSession = computed<EventSession | null>(() => {
  return props.sessions.find(s => s.id === selectedSessionId.value) ?? null
})

watch(() => props.sessions, (s) => {
  if (s.length && selectedSessionId.value === null) {
    selectedSessionId.value = s[0]!.id
  }
}, { immediate: true })

const totalCents = computed(() => {
  if (!selectedSession.value) return 0
  let total = 0
  for (const cat of selectedSession.value.ticket_categories) {
    total += (quantities.value[cat.id] ?? 0) * cat.price_cents
  }
  return total
})

const totalQty = computed(() => {
  let total = 0
  for (const v of Object.values(quantities.value)) total += v
  return total
})

function adjust(catId: number, delta: number, max: number) {
  const next = Math.max(0, Math.min(max, (quantities.value[catId] ?? 0) + delta))
  quantities.value = { ...quantities.value, [catId]: next }
}

function goToCheckout() {
  if (!auth.isAuthenticated) {
    router.push({ path: '/login', query: { redirect: route.fullPath } })
    return
  }
  const items = Object.entries(quantities.value)
    .filter(([, qty]) => qty > 0)
    .map(([id, qty]) => `${id}:${qty}`)
    .join(',')
  router.push({
    path: '/checkout',
    query: {
      session_id: String(selectedSessionId.value),
      items,
    },
  })
}
</script>

<template>
  <aside class="lg:sticky lg:top-6 self-start rounded-lg border border-slate-200 bg-slate-50 p-5 space-y-4">
    <h2 class="text-lg font-semibold">
      Réserver vos billets
    </h2>

    <div>
      <label class="text-sm font-medium text-slate-700 block mb-1">Session</label>
      <select
        v-model.number="selectedSessionId"
        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
      >
        <option v-for="s in props.sessions" :key="s.id" :value="s.id">
          {{ formatDateTime(s.starts_at) }}
        </option>
      </select>
    </div>

    <div v-if="selectedSession" class="space-y-2">
      <div
        v-for="cat in selectedSession.ticket_categories"
        :key="cat.id"
        class="flex items-center justify-between gap-2 rounded border border-slate-200 bg-white p-3"
      >
        <div>
          <p class="text-sm font-medium">
            {{ cat.name }}
          </p>
          <p class="text-xs text-slate-500">
            {{ formatPrice(cat.price_cents) }} · {{ cat.remaining }} restants
          </p>
        </div>
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="rounded border border-slate-300 px-2 leading-none"
            @click="adjust(cat.id, -1, cat.remaining)"
          >
            −
          </button>
          <span class="w-6 text-center text-sm">{{ quantities[cat.id] ?? 0 }}</span>
          <button
            type="button"
            class="rounded border border-slate-300 px-2 leading-none"
            @click="adjust(cat.id, 1, cat.remaining)"
          >
            +
          </button>
        </div>
      </div>
    </div>

    <div class="flex items-center justify-between border-t border-slate-200 pt-3 text-sm">
      <span class="text-slate-500">Total ({{ totalQty }} billet<span v-if="totalQty > 1">s</span>)</span>
      <span class="font-semibold">{{ formatPrice(totalCents) }}</span>
    </div>

    <button
      type="button"
      :disabled="totalQty === 0"
      class="w-full rounded-md bg-brand-600 px-4 py-2 text-white font-medium hover:bg-brand-700 disabled:opacity-50"
      @click="goToCheckout"
    >
      Acheter
    </button>
  </aside>
</template>
