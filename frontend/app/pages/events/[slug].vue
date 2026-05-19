<script setup lang="ts">
import type { Event, EventSession, SingleResponse } from '~/types/event'
import { categoryLabel, formatDateTime, formatPrice } from '~/utils/format'

definePageMeta({ layout: 'default' })

const route = useRoute()
const router = useRouter()
const api = useApi()
const auth = useAuthStore()

const slug = computed(() => route.params.slug as string)

const { data: eventData, error } = await useAsyncData<SingleResponse<Event>>(
  () => `event-${slug.value}`,
  () => api<SingleResponse<Event>>(`/events/${slug.value}`),
)
const event = computed<Event | null>(() => eventData.value?.data ?? null)

const { data: sessionsData } = await useAsyncData<{ data: EventSession[] }>(
  () => `event-${slug.value}-sessions`,
  () => api<{ data: EventSession[] }>(`/events/${slug.value}/sessions`),
)
const sessions = computed<EventSession[]>(() => sessionsData.value?.data ?? [])

useHead(() => ({
  title: event.value ? `${event.value.title} · Resonance` : 'Événement · Resonance',
}))

// Carte de billetterie : sélecteur session + map quantités par catégorie.
const selectedSessionId = ref<number | null>(null)
const quantities = ref<Record<number, number>>({})

const selectedSession = computed<EventSession | null>(() => {
  return sessions.value.find(s => s.id === selectedSessionId.value) ?? null
})

watch(sessions, (s) => {
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
  <div v-if="error" class="mx-auto max-w-7xl px-4 py-12 text-red-600">
    Événement introuvable.
  </div>

  <div v-else-if="event" class="bg-white">
    <!-- Hero pleine largeur (LCP) -->
    <!--
      @perf-debt: pas de fetchpriority="high", pas de preload, pas de
      <NuxtImg> (formats AVIF/WebP, srcset). Image native 1920×1080, ~400 Ko
      qui ralentit dramatiquement la LCP. Résolu en J1/J2.
    -->
    <img
      v-if="event.cover_image_url"
      :src="event.cover_image_url"
      :alt="event.title"
      class="w-full h-[420px] object-cover"
    >

    <div class="mx-auto max-w-7xl px-4 py-10 grid gap-10 lg:grid-cols-[1fr_380px]">
      <!-- Colonne gauche -->
      <div class="space-y-8">
        <header>
          <p class="text-sm font-medium uppercase tracking-wide text-brand-700 mb-2">
            {{ categoryLabel(event.category) }}
          </p>
          <h1 class="text-3xl sm:text-4xl font-bold mb-3">
            {{ event.title }}
          </h1>
          <p class="text-slate-500">
            {{ event.venue_name }} · {{ event.city }}
          </p>
          <p v-if="event.organizer" class="text-sm text-slate-500 mt-1">
            Organisé par <strong class="text-slate-700">{{ event.organizer.company_name }}</strong>
          </p>
        </header>

        <article class="prose prose-slate max-w-none whitespace-pre-line">
          {{ event.description }}
        </article>

        <!-- Galerie média -->
        <section v-if="event.media && event.media.length">
          <h2 class="text-xl font-semibold mb-3">
            Galerie
          </h2>
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
            <img
              v-for="m in event.media"
              :key="m.id"
              :src="m.url"
              :alt="m.alt_text ?? ''"
              class="aspect-video w-full object-cover rounded"
            >
          </div>
        </section>

        <!-- Liste des sessions + dispo -->
        <section v-if="sessions.length">
          <h2 class="text-xl font-semibold mb-3">
            Sessions
          </h2>
          <ul class="space-y-2">
            <li
              v-for="s in sessions"
              :key="s.id"
              class="flex items-center justify-between rounded border border-slate-200 px-4 py-3"
            >
              <span>{{ formatDateTime(s.starts_at) }}</span>
              <span class="text-sm text-slate-500">
                {{ s.ticket_categories.reduce((acc, c) => acc + c.remaining, 0) }} places restantes
              </span>
            </li>
          </ul>
        </section>
      </div>

      <!--
        Carte de billetterie sticky (sélecteur session + catégories + total).
        @perf-debt: pas de lazy hydration / <ClientOnly>. La carte hydrate
        avec le reste de la page → plus de JS bloquant pour le LCP. Résolu
        en J2 atelier "j2-bundle" (lazy hydration manuelle).
      -->
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
            <option v-for="s in sessions" :key="s.id" :value="s.id">
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
    </div>
  </div>
</template>
