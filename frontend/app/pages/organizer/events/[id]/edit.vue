<script setup lang="ts">
import type { Event, EventCategory, SingleResponse } from '~/types/event'

definePageMeta({
  layout: 'organizer',
  middleware: ['auth', 'organizer'],
})

const route = useRoute()
const router = useRouter()
const api = useApi()

const isNew = computed(() => route.params.id === 'new')
const eventId = computed(() => route.params.id as string)

const CATEGORIES: EventCategory[] = [
  'concert', 'festival', 'theater', 'conference', 'exhibition',
]

interface FormState {
  title: string
  description: string
  category: EventCategory
  city: string
  country: string
  venue_name: string
  status: 'draft' | 'published'
}

const form = ref<FormState>({
  title: '',
  description: '',
  category: 'concert',
  city: '',
  country: 'FR',
  venue_name: '',
  status: 'draft',
})

const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)

useHead(() => ({
  title: isNew.value ? 'Nouvel événement · Resonance' : `Édition · ${form.value.title}`,
}))

async function load() {
  if (isNew.value) {
    loading.value = false
    return
  }
  try {
    const resp = await api<SingleResponse<Event>>(`/organizer/events/${eventId.value}`)
    form.value = {
      title: resp.data.title,
      description: resp.data.description,
      category: resp.data.category,
      city: resp.data.city,
      country: resp.data.country,
      venue_name: resp.data.venue_name,
      status: resp.data.status === 'archived' ? 'draft' : (resp.data.status as 'draft' | 'published'),
    }
  }
  catch {
    error.value = 'Événement introuvable.'
  }
  finally {
    loading.value = false
  }
}

// Équivalent du `{ server: false }` de useAsyncData : déclenchement dans
// `onMounted` (côté client uniquement) pour éviter un fetch SSR sans Bearer
// token et garantir l'égalité SSR/CSR-initial-render.
onMounted(() => { load() })

async function save() {
  saving.value = true
  error.value = null
  try {
    if (isNew.value) {
      const resp = await api<SingleResponse<Event>>('/organizer/events', {
        method: 'POST',
        body: form.value,
      })
      await router.push(`/organizer/events/${resp.data.id}/edit`)
    }
    else {
      await api(`/organizer/events/${eventId.value}`, {
        method: 'PATCH',
        body: form.value,
      })
    }
  }
  catch (e: unknown) {
    const err = e as { data?: { message?: string, errors?: Record<string, string[]> } }
    error.value = err?.data?.errors
      ? Object.values(err.data.errors).flat().join(' · ')
      : (err?.data?.message ?? 'Sauvegarde impossible.')
  }
  finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl px-4 py-8">
    <NuxtLink to="/organizer/events" class="text-sm text-slate-500 hover:underline">
      ← Mes événements
    </NuxtLink>
    <h1 class="text-2xl font-bold mt-2 mb-6">
      {{ isNew ? 'Nouvel événement' : 'Éditer l\'événement' }}
    </h1>

    <p v-if="loading" class="text-slate-500">
      Chargement…
    </p>
    <p v-if="error" class="rounded border border-red-300 bg-red-50 p-3 text-red-700 text-sm mb-6">
      {{ error }}
    </p>

    <form v-if="!loading" class="space-y-4" @submit.prevent="save">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Titre</label>
        <input
          v-model="form.title"
          type="text"
          required
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        >
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
        <textarea
          v-model="form.description"
          required
          rows="6"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Catégorie</label>
          <select v-model="form.category" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            <option v-for="cat in CATEGORIES" :key="cat" :value="cat">
              {{ cat }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Statut</label>
          <select v-model="form.status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            <option value="draft">
              Brouillon
            </option>
            <option value="published">
              Publié
            </option>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-4">
        <div class="col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Ville</label>
          <input
            v-model="form.city"
            type="text"
            required
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          >
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Pays (ISO 2)</label>
          <input
            v-model="form.country"
            type="text"
            maxlength="2"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm uppercase"
          >
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Salle / Lieu</label>
        <input
          v-model="form.venue_name"
          type="text"
          required
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
        >
      </div>

      <div class="flex justify-end">
        <button
          type="submit"
          :disabled="saving"
          class="rounded-md bg-brand-600 px-6 py-2 text-white font-medium hover:bg-brand-700 disabled:opacity-50"
        >
          {{ saving ? 'Sauvegarde…' : 'Enregistrer' }}
        </button>
      </div>
    </form>
  </div>
</template>
