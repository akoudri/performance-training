<script setup lang="ts">
import type { EventSession, Order, SingleResponse, TicketCategory } from '~/types/event'
import { formatDateTime, formatPrice } from '~/utils/format'

definePageMeta({ layout: 'default', middleware: 'auth' })
useHead({ title: 'Validation de commande · Resonance' })

const route = useRoute()
const router = useRouter()
const api = useApi()
const auth = useAuthStore()

interface CheckoutItem {
  category: TicketCategory
  quantity: number
}

const sessionId = ref<number>(Number(route.query.session_id ?? 0))
const items = ref<CheckoutItem[]>([])
const session = ref<EventSession | null>(null)

const step = ref<1 | 2 | 3>(1)
const holderName = ref('')
const email = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

// Reconstitue les items depuis ?items=catId:qty,catId:qty et ?session_id=…
async function loadSession() {
  if (!sessionId.value) {
    error.value = 'Session manquante.'
    return
  }
  // On a besoin de la fiche événement pour récupérer les TicketCategory
  // détaillées. La page event détaille passe par /events/{slug}/sessions —
  // côté checkout on n'a que session_id, on requête donc directement par
  // session via une recherche sur les events. Pas idéal mais starter.
  const raw = (route.query.items as string) ?? ''
  const parsed: Array<{ id: number, qty: number }> = raw
    .split(',')
    .filter(Boolean)
    .map((part) => {
      const [id, qty] = part.split(':').map(n => Number(n))
      return { id: id ?? 0, qty: qty ?? 0 }
    })
    .filter(p => p.id > 0 && p.qty > 0)

  if (!parsed.length) {
    error.value = 'Aucun billet sélectionné.'
    return
  }

  // Astuce starter : on retrouve la session en chargeant tous les events et
  // en cherchant la session matching. Coûteux, mais cohérent avec l'esprit
  // §8 (pas d'optim). Mieux : ajouter un endpoint /sessions/{id} en J3.
  // @perf-debt: requête /events?per_page=100 pour reconstituer une session
  //             — résolu en J3 (endpoint dédié).
  type EventLite = { slug: string }
  const search = await api<{ data: EventLite[] }>('/events', { query: { per_page: 100 } })
  for (const ev of search.data) {
    try {
      const resp = await api<{ data: EventSession[] }>(`/events/${ev.slug}/sessions`)
      const found = resp.data.find(s => s.id === sessionId.value)
      if (found) {
        session.value = found
        break
      }
    }
    catch {
      // ignore
    }
  }

  if (!session.value) {
    error.value = 'Session introuvable.'
    return
  }

  items.value = parsed
    .map((p) => {
      const cat = session.value!.ticket_categories.find(c => c.id === p.id)
      return cat ? { category: cat, quantity: p.qty } : null
    })
    .filter((x): x is CheckoutItem => x !== null)

  if (auth.user?.email) email.value = auth.user.email
  if (auth.user?.name) holderName.value = auth.user.name
}

// Équivalent du `{ server: false }` de useAsyncData : la séquence de
// fetches part dans `onMounted` (côté client uniquement). SSR et
// CSR-initial-render sont identiques (refs vides) — pas de mismatch
// d'hydratation — et le `auth.user` est disponible quand on préremplit
// `email` / `holderName` après le mount.
onMounted(() => { loadSession() })

const totalCents = computed(() =>
  items.value.reduce((acc, it) => acc + it.category.price_cents * it.quantity, 0),
)
const totalQty = computed(() =>
  items.value.reduce((acc, it) => acc + it.quantity, 0),
)

async function pay() {
  if (!session.value) return
  error.value = null
  submitting.value = true
  try {
    const payload = {
      event_session_id: session.value.id,
      items: items.value.map(it => ({
        ticket_category_id: it.category.id,
        quantity: it.quantity,
        holder_name: holderName.value,
      })),
    }
    const resp = await api<SingleResponse<Order>>('/orders', {
      method: 'POST',
      body: payload,
    })
    await router.push(`/account/tickets?order=${resp.data.id}`)
  }
  catch (e: unknown) {
    const err = e as { data?: { message?: string, errors?: Record<string, string[]> } }
    error.value = err?.data?.errors
      ? Object.values(err.data.errors).flat().join(' · ')
      : (err?.data?.message ?? 'Le paiement a échoué.')
  }
  finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl px-4 py-10">
    <NuxtLink to="/" class="text-sm text-slate-500 hover:underline mb-4 inline-block">
      ← Retour
    </NuxtLink>

    <h1 class="text-2xl font-bold mb-6">
      Validation de votre commande
    </h1>

    <!-- Stepper -->
    <ol class="flex items-center gap-2 text-sm mb-8">
      <li
        v-for="(label, i) in ['Récap', 'Coordonnées', 'Paiement']"
        :key="label"
        class="flex items-center gap-2"
      >
        <span
          class="rounded-full px-2 py-0.5 text-xs font-semibold"
          :class="step >= (i + 1) ? 'bg-brand-600 text-white' : 'bg-slate-200 text-slate-500'"
        >
          {{ i + 1 }}
        </span>
        <span :class="step >= (i + 1) ? 'text-slate-900' : 'text-slate-500'">{{ label }}</span>
        <span v-if="i < 2" class="text-slate-300">→</span>
      </li>
    </ol>

    <p v-if="error" class="rounded border border-red-300 bg-red-50 p-3 text-red-700 text-sm mb-6">
      {{ error }}
    </p>

    <div v-if="session" class="space-y-6">
      <!-- Récap -->
      <section v-show="step === 1" class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
        <h2 class="text-lg font-semibold">
          Récapitulatif
        </h2>
        <p class="text-sm text-slate-500">
          Session du {{ formatDateTime(session.starts_at) }}
        </p>
        <ul class="divide-y divide-slate-100">
          <li v-for="it in items" :key="it.category.id" class="py-2 flex justify-between text-sm">
            <span>{{ it.category.name }} × {{ it.quantity }}</span>
            <span>{{ formatPrice(it.category.price_cents * it.quantity) }}</span>
          </li>
        </ul>
        <div class="flex justify-between border-t border-slate-200 pt-3 text-sm font-semibold">
          <span>Total ({{ totalQty }} billet<span v-if="totalQty > 1">s</span>)</span>
          <span>{{ formatPrice(totalCents) }}</span>
        </div>
        <div class="text-right">
          <button
            class="rounded-md bg-brand-600 px-4 py-2 text-white font-medium hover:bg-brand-700"
            @click="step = 2"
          >
            Continuer
          </button>
        </div>
      </section>

      <!-- Coordonnées -->
      <section v-show="step === 2" class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
        <h2 class="text-lg font-semibold">
          Coordonnées du porteur
        </h2>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Nom complet</label>
          <input
            v-model="holderName"
            type="text"
            required
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          >
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
          <input
            v-model="email"
            type="email"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          >
        </div>
        <div class="flex justify-between">
          <button class="text-sm text-slate-500 hover:underline" @click="step = 1">
            ← Retour
          </button>
          <button
            class="rounded-md bg-brand-600 px-4 py-2 text-white font-medium hover:bg-brand-700"
            :disabled="!holderName.trim()"
            @click="step = 3"
          >
            Continuer
          </button>
        </div>
      </section>

      <!-- Paiement -->
      <section v-show="step === 3" class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
        <h2 class="text-lg font-semibold">
          Paiement
        </h2>
        <p class="text-sm text-slate-500">
          Paiement simulé pour la formation. Latence aléatoire 800–1500 ms côté
          backend (cf. spec §8 / @perf-debt mock paiement synchrone).
        </p>
        <div class="rounded-md bg-slate-50 border border-slate-200 p-4 text-sm space-y-1">
          <p>Total à régler : <strong>{{ formatPrice(totalCents) }}</strong></p>
          <p class="text-slate-500">
            Mode : carte bancaire (mock)
          </p>
        </div>
        <div class="flex justify-between">
          <button class="text-sm text-slate-500 hover:underline" @click="step = 2">
            ← Retour
          </button>
          <button
            :disabled="submitting"
            class="rounded-md bg-brand-600 px-6 py-2 text-white font-medium hover:bg-brand-700 disabled:opacity-50"
            @click="pay"
          >
            {{ submitting ? 'Paiement en cours…' : 'Simuler le paiement' }}
          </button>
        </div>
      </section>
    </div>
  </div>
</template>
