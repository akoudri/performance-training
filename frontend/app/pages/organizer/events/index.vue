<script setup lang="ts">
import type { Event } from '~/types/event'
import { categoryLabel } from '~/utils/format'

definePageMeta({
  layout: 'organizer',
  middleware: ['auth', 'organizer'],
})
useHead({ title: 'Mes événements · Resonance' })

const api = useApi()

const events = ref<Event[]>([])
const pending = ref(true)
const error = ref<string | null>(null)

async function load() {
  pending.value = true
  try {
    const resp = await api<{ data: Event[] }>('/organizer/events')
    events.value = resp.data
  }
  catch (e) {
    error.value = (e as Error).message ?? 'Erreur'
  }
  finally {
    pending.value = false
  }
}

// Équivalent du `{ server: false }` de useAsyncData : déclenchement
// dans `onMounted` (côté client uniquement) pour que SSR et CSR-initial
// rendent la même chose (refs vides) et que le fetch parte avec le Bearer
// token restauré par `plugins/auth.client.ts`.
onMounted(() => { load() })

async function archive(id: number) {
  if (!confirm('Archiver cet événement ?')) return
  try {
    await api(`/organizer/events/${id}`, { method: 'DELETE' })
    await load()
  }
  catch (e) {
    error.value = (e as Error).message ?? 'Suppression impossible.'
  }
}
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-8">
    <header class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold">
        Mes événements
      </h1>
      <NuxtLink
        to="/organizer/events/new/edit"
        class="rounded-md bg-brand-600 px-4 py-2 text-white text-sm font-medium hover:bg-brand-700"
      >
        + Créer un événement
      </NuxtLink>
    </header>

    <p v-if="error" class="text-red-600 mb-4">
      {{ error }}
    </p>
    <p v-if="pending" class="text-slate-500">
      Chargement…
    </p>

    <table v-else class="w-full text-sm bg-white rounded-lg overflow-hidden border border-slate-200">
      <thead class="bg-slate-50 text-left">
        <tr>
          <th class="px-5 py-2 font-medium text-slate-600">
            Titre
          </th>
          <th class="px-5 py-2 font-medium text-slate-600">
            Catégorie
          </th>
          <th class="px-5 py-2 font-medium text-slate-600">
            Ville
          </th>
          <th class="px-5 py-2 font-medium text-slate-600">
            Statut
          </th>
          <th class="px-5 py-2 font-medium text-slate-600 text-right">
            Actions
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="ev in events"
          :key="ev.id"
          class="border-t border-slate-100 hover:bg-slate-50"
        >
          <td class="px-5 py-2">
            {{ ev.title }}
          </td>
          <td class="px-5 py-2 text-slate-500">
            {{ categoryLabel(ev.category) }}
          </td>
          <td class="px-5 py-2 text-slate-500">
            {{ ev.city }}
          </td>
          <td class="px-5 py-2 text-slate-500">
            {{ ev.status }}
          </td>
          <td class="px-5 py-2 text-right space-x-3">
            <NuxtLink
              :to="`/organizer/events/${ev.id}/edit`"
              class="text-brand-700 hover:underline"
            >
              Éditer
            </NuxtLink>
            <NuxtLink
              :to="`/organizer/events/${ev.id}/participants`"
              class="text-brand-700 hover:underline"
            >
              Participants
            </NuxtLink>
            <button class="text-red-600 hover:underline" @click="archive(ev.id)">
              Archiver
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
