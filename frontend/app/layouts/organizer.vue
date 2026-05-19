<script setup lang="ts">
// @perf-fix: Chart.js retiré du layout (était importé statiquement, incluait
// ~160 Ko raw / ~56 Ko gzipped dans le First Load JS de TOUTES les pages
// organizer). L'import est désormais isolé dans `~/components/SalesChart.vue`,
// chargé via `defineAsyncComponent` uniquement par /organizer/dashboard.
// — solution/j2-bundle.
</script>

<template>
  <div class="min-h-screen flex flex-col bg-slate-50 text-slate-900 font-sans">
    <header class="bg-slate-900 text-white">
      <div class="mx-auto max-w-7xl px-4 py-4 flex items-center gap-6">
        <NuxtLink to="/organizer/dashboard" class="text-lg font-bold tracking-tight">
          Resonance · Espace organisateur
        </NuxtLink>
        <nav class="flex items-center gap-4 text-sm text-slate-200">
          <NuxtLink to="/organizer/dashboard" class="hover:text-white">
            Dashboard
          </NuxtLink>
          <NuxtLink to="/organizer/events" class="hover:text-white">
            Événements
          </NuxtLink>
        </nav>
        <div class="ml-auto">
          <NuxtLink to="/" class="text-sm text-slate-300 hover:text-white">
            ← Retour au site
          </NuxtLink>
        </div>
      </div>
    </header>

    <main class="flex-1">
      <!-- Tout l'espace organisateur est rendu côté client (pas d'SSR
           pour le back-office) — équivalent au `routeRules: { ssr: false }`
           prévu en final. Pas de routeRules ici (interdit par §8) : on isole
           dans <ClientOnly>. -->
      <ClientOnly>
        <slot />
        <template #fallback>
          <div class="mx-auto max-w-7xl px-4 py-10 text-slate-500">
            Chargement de l'espace organisateur…
          </div>
        </template>
      </ClientOnly>
    </main>
  </div>
</template>
