<script setup lang="ts">
import type { Event } from '~/types/event'
import { categoryLabel, formatDate } from '~/utils/format'

defineProps<{ event: Event }>()
</script>

<template>
  <NuxtLink
    :to="`/events/${event.slug}`"
    class="group block overflow-hidden rounded-lg border border-slate-200 bg-white transition hover:shadow-md"
  >
    <!--
      @perf-debt: image full-size servie depuis MinIO (1920×1080, ~400 Ko),
      pas de srcset/sizes, pas de format AVIF/WebP négocié, pas de
      lazy-loading explicite ici (le navigateur applique sa stratégie par
      défaut). Résolu en J2 atelier "j2-bundle" via <NuxtImg>.
    -->
    <img
      v-if="event.cover_image_url"
      :src="event.cover_image_url"
      :alt="event.title"
      class="aspect-video w-full object-cover transition group-hover:scale-105"
    >
    <div class="p-4 space-y-2">
      <p class="text-xs font-medium uppercase tracking-wide text-brand-700">
        {{ categoryLabel(event.category) }}
      </p>
      <h3 class="font-semibold text-slate-900 line-clamp-2">
        {{ event.title }}
      </h3>
      <p class="text-sm text-slate-500">
        {{ event.city }}<span v-if="event.published_at"> · {{ formatDate(event.published_at) }}</span>
      </p>
    </div>
  </NuxtLink>
</template>
