<script setup lang="ts">
import type { Event, EventSession, SingleResponse } from '~/types/event'
import { categoryLabel, formatDateTime } from '~/utils/format'

definePageMeta({ layout: 'default' })

const route = useRoute()
const api = useApi()

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

// LCP hero : on s'appuie uniquement sur `fetchpriority="high"` côté
// <NuxtImg>. Cf. note dans pages/index.vue pour pourquoi on n'écrit
// pas de `<link rel="preload" as="image">` manuel ni via la prop
// `preload` de NuxtImg (mismatch préload/srcset coûteux sur mobile).
// @perf-fix: hero image fetchpriority="high" — solution/j2-bundle.
useHead(() => ({
  title: event.value ? `${event.value.title} · Resonance` : 'Événement · Resonance',
}))
</script>

<template>
  <div v-if="error" class="mx-auto max-w-7xl px-4 py-12 text-red-600">
    Événement introuvable.
  </div>

  <div v-else-if="event" class="bg-white">
    <!-- Hero pleine largeur (LCP) -->
    <!--
      @perf-fix: <NuxtImg> + fetchpriority="high" + preload (cf. useHead).
      LCP candidate (spec §5 écran 3) — solution/j2-bundle. AVIF/WebP,
      sizes responsive, resize 1600×420 côté IPX.
    -->
    <NuxtImg
      v-if="event.cover_image_url"
      :src="useImageSrc(event.cover_image_url)"
      :alt="event.title"
      preset="hero"
      width="1600"
      height="420"
      sizes="100vw sm:100vw md:100vw lg:1280px"
      fetchpriority="high"
      loading="eager"
      class="w-full h-[420px] object-cover"
    />

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
          <!--
            @perf-fix: galerie en <NuxtImg> + lazy loading natif. Les
            vignettes sont below-fold, donc lazy par défaut ; AVIF/WebP
            négocié + resize 480×270 (ratio 16:9 du grid). Économise
            beaucoup sur les fiches qui ont 6-8 médias en pool.
            — solution/j2-bundle.
          -->
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
            <NuxtImg
              v-for="m in event.media"
              :key="m.id"
              :src="useImageSrc(m.url)"
              :alt="m.alt_text ?? ''"
              preset="gallery"
              width="480"
              height="270"
              sizes="50vw sm:33vw md:33vw lg:300px"
              loading="lazy"
              class="aspect-video w-full object-cover rounded"
            />
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
        @perf-fix: TicketSelector extrait + lazy hydration via la convention
        Nuxt 4 `<LazyXxx hydrate-on-idle>`. SSR rend l'aside complet (pas
        de mismatch d'hydratation, l'utilisateur voit le sélecteur tout de
        suite) ; la mise en place des event handlers et watchers attend
        que le browser soit idle, ce qui débloque le LCP du hero pendant
        que les ressources critical-path s'hydratent. — solution/j2-bundle.
      -->
      <LazyTicketSelector
        hydrate-on-idle
        :event="event"
        :sessions="sessions"
      />
    </div>
  </div>
</template>
