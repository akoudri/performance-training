<script setup lang="ts">
import type { Ticket } from '~/types/event'
import TicketQrCode from '~/components/TicketQrCode.vue'
import { formatDateTime, formatPrice } from '~/utils/format'

definePageMeta({ layout: 'default', middleware: 'auth' })
useHead({ title: 'Mes billets · Resonance' })

const api = useApi()
const route = useRoute()

// Équivalent du `{ server: false }` de useAsyncData : déclenchement dans
// `onMounted` (côté client uniquement). SSR et CSR-initial-render sont
// identiques (`tickets=[]`, `pending=true`) — pas de mismatch — et le
// fetch part avec le Bearer token restauré par `plugins/auth.client.ts`.
const tickets = ref<Ticket[]>([])
const pending = ref(true)
const error = ref<string | null>(null)

async function refresh() {
  pending.value = true
  error.value = null
  try {
    const resp = await api<{ data: Ticket[] }>('/me/tickets')
    tickets.value = resp.data
  }
  catch {
    error.value = 'Erreur lors du chargement des billets.'
  }
  finally {
    pending.value = false
  }
}

onMounted(() => { refresh() })

// Quand l'utilisateur revient depuis le checkout (?order=…) on rafraîchit
// la liste : la commande paid vient d'être créée.
const justOrdered = computed(() => Boolean(route.query.order))
</script>

<template>
  <div class="mx-auto max-w-5xl px-4 py-10">
    <h1 class="text-2xl font-bold mb-2">
      Mes billets
    </h1>
    <p class="text-slate-500 mb-8 text-sm">
      Présentez le QR code à l'entrée de l'événement.
    </p>

    <div
      v-if="justOrdered"
      class="mb-6 rounded border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800"
    >
      Commande #{{ route.query.order }} confirmée — vos billets sont disponibles ci-dessous.
      <button class="underline ml-2" @click="refresh">
        Actualiser
      </button>
    </div>

    <p v-if="error" class="text-red-600">
      Erreur lors du chargement des billets.
    </p>
    <p v-else-if="pending" class="text-slate-500">
      Chargement…
    </p>
    <p v-else-if="!tickets.length" class="text-slate-500">
      Vous n'avez pas encore acheté de billet.
      <NuxtLink to="/events" class="text-brand-700 hover:underline">
        Découvrir les événements
      </NuxtLink>.
    </p>

    <ul v-else class="space-y-4">
      <li
        v-for="t in tickets"
        :key="t.id"
        class="rounded-lg border border-slate-200 bg-white p-5 grid gap-4 sm:grid-cols-[160px_1fr]"
      >
        <TicketQrCode :value="t.code" />
        <div class="space-y-1">
          <NuxtLink
            :to="`/events/${t.event_session.event.slug}`"
            class="font-semibold hover:underline"
          >
            {{ t.event_session.event.title }}
          </NuxtLink>
          <p class="text-sm text-slate-500">
            {{ formatDateTime(t.event_session.starts_at) }}
          </p>
          <p class="text-sm">
            {{ t.ticket_category.name }} · {{ formatPrice(t.ticket_category.price_cents) }}
          </p>
          <p class="text-xs text-slate-400">
            Code : <code>{{ t.code }}</code>
          </p>
          <p class="text-xs">
            Statut :
            <span
              :class="{
                'text-emerald-700': t.status === 'valid',
                'text-slate-500': t.status === 'used',
                'text-red-600': t.status === 'cancelled',
              }"
            >
              {{ t.status }}
            </span>
          </p>
        </div>
      </li>
    </ul>
  </div>
</template>
