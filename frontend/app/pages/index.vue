<script setup lang="ts">
import type { Event, EventCategory } from '~/types/event'
import EventCard from '~/components/EventCard.vue'
import { categoryLabel } from '~/utils/format'

definePageMeta({ layout: 'default' })
useHead({ title: 'Resonance — billetterie d\'événements' })

const api = useApi()

// 1 fetch global pour la home — pas de mémoization, refetch à chaque mount.
// @perf-debt: pas de cache (ni Redis backend, ni ISR Nuxt). Résolu en J1
// (CDN cache + ISR) et J3 (Redis cache).
// @perf-debt: l'endpoint /events ne paginant plus en starter, le payload
// contient TOUS les events publiés (1 200 sur le seed réaliste). On ne
// consomme que les 21 premiers via slice — gaspillage massif côté réseau
// et parsing JSON. Résolu en branche solution/j2-frontend.
const { data, pending, error } = await useAsyncData<{ data: Event[] }>(
  'home-events',
  () => api<{ data: Event[] }>('/events'),
)

const events = computed<Event[]>(() => data.value?.data ?? [])
const hero = computed<Event | undefined>(() => events.value[0])
const thisWeek = computed<Event[]>(() => events.value.slice(1, 9))
const popular = computed<Event[]>(() => events.value.slice(9, 21))

const CATEGORIES: EventCategory[] = [
  'concert', 'festival', 'theater', 'conference', 'exhibition',
]
</script>

<template>
  <div>
    <!-- ============== Hero ============== -->
    <section v-if="hero" class="relative bg-slate-900 text-white">
      <!--
        @perf-debt: pas de `fetchpriority="high"`, pas de preload via
        useHead({ link: [{ rel:'preload', as:'image' }] }). Cette image
        est la LCP candidate principale (cf. spec §5 écran 1) — résolu
        en J1 atelier "j1-cdn-cache".
      -->
      <img
        v-if="hero.cover_image_url"
        :src="hero.cover_image_url"
        :alt="hero.title"
        class="absolute inset-0 h-full w-full object-cover opacity-50"
      >
      <div class="relative mx-auto max-w-7xl px-4 py-24">
        <p class="text-sm font-medium uppercase tracking-wide text-brand-100 mb-2">
          {{ categoryLabel(hero.category) }} · à la une
        </p>
        <h1 class="text-4xl sm:text-5xl font-bold mb-4 max-w-2xl">
          {{ hero.title }}
        </h1>
        <p class="text-slate-200 max-w-xl mb-6 line-clamp-3">
          {{ hero.description }}
        </p>
        <NuxtLink
          :to="`/events/${hero.slug}`"
          class="inline-block rounded-md bg-white px-5 py-3 text-slate-900 font-medium hover:bg-slate-100"
        >
          Découvrir l'événement
        </NuxtLink>
      </div>
    </section>

    <!-- ============== Filtres rapides ============== -->
    <section class="border-b border-slate-200">
      <div class="mx-auto max-w-7xl px-4 py-4 flex flex-wrap gap-2 text-sm">
        <NuxtLink
          to="/events"
          class="rounded-full border border-slate-300 px-3 py-1 hover:bg-slate-100"
        >
          Tous
        </NuxtLink>
        <NuxtLink
          v-for="cat in CATEGORIES"
          :key="cat"
          :to="{ path: '/events', query: { category: cat } }"
          class="rounded-full border border-slate-300 px-3 py-1 hover:bg-slate-100"
        >
          {{ categoryLabel(cat) }}
        </NuxtLink>
      </div>
    </section>

    <p v-if="error" class="mx-auto max-w-7xl px-4 py-8 text-red-600">
      Erreur de chargement des événements.
    </p>
    <p v-else-if="pending" class="mx-auto max-w-7xl px-4 py-8 text-slate-500">
      Chargement…
    </p>

    <!-- ============== Cette semaine ============== -->
    <section v-if="thisWeek.length" class="mx-auto max-w-7xl px-4 py-12">
      <div class="flex items-end justify-between mb-6">
        <h2 class="text-2xl font-bold">
          Cette semaine
        </h2>
        <NuxtLink to="/events" class="text-sm text-brand-700 hover:underline">
          Voir tout →
        </NuxtLink>
      </div>
      <!-- Carrousel horizontal façon scroll-snap -->
      <div class="flex gap-4 overflow-x-auto pb-4 -mx-4 px-4 snap-x">
        <div v-for="ev in thisWeek" :key="ev.id" class="snap-start shrink-0 w-72">
          <EventCard :event="ev" />
        </div>
      </div>
    </section>

    <!-- ============== Les plus populaires ============== -->
    <section v-if="popular.length" class="mx-auto max-w-7xl px-4 py-12 border-t border-slate-200">
      <h2 class="text-2xl font-bold mb-6">
        Les plus populaires
      </h2>
      <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
        <EventCard v-for="ev in popular" :key="ev.id" :event="ev" />
      </div>
    </section>

    <!-- ============== Par catégorie ============== -->
    <section class="mx-auto max-w-7xl px-4 py-12 border-t border-slate-200">
      <h2 class="text-2xl font-bold mb-6">
        Par catégorie
      </h2>
      <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-5">
        <NuxtLink
          v-for="cat in CATEGORIES"
          :key="cat"
          :to="{ path: '/events', query: { category: cat } }"
          class="group rounded-lg bg-brand-600 hover:bg-brand-700 text-white p-6 text-center font-semibold"
        >
          {{ categoryLabel(cat) }}
        </NuxtLink>
      </div>
    </section>
  </div>
</template>
