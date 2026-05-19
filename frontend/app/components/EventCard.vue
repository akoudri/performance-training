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
      @perf-fix: <NuxtImg> + preset card (AVIF/WebP, resize 480×270,
      lazy-loading natif). Économie majeure : on passe de 400 Ko/img full-
      size à ~25-40 Ko AVIF — multiplié par 12-20 cards visibles sur la
      home, c'est ~5 Mo de moins au-dessus du fold. — solution/j2-bundle.
    -->
    <NuxtImg
      v-if="event.cover_image_url"
      :src="useImageSrc(event.cover_image_url)"
      :alt="event.title"
      preset="card"
      width="480"
      height="270"
      sizes="100vw sm:50vw lg:25vw xl:300px"
      loading="lazy"
      class="aspect-video w-full object-cover transition group-hover:scale-105"
    />
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
