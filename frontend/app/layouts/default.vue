<script setup lang="ts">
import { useAuth } from '~/composables/useAuth'

const { isAuthenticated, isOrganizer, user, logout } = useAuth()

const search = ref('')

function submitSearch() {
  if (!search.value.trim()) return
  navigateTo({ path: '/events', query: { q: search.value.trim() } })
}
</script>

<template>
  <div class="min-h-screen flex flex-col bg-white text-slate-900 font-sans">
    <header class="border-b border-slate-200">
      <div class="mx-auto max-w-7xl px-4 py-4 flex items-center gap-6">
        <NuxtLink to="/" class="text-xl font-bold text-brand-700 tracking-tight">
          Resonance
        </NuxtLink>

        <form class="flex-1 max-w-lg" @submit.prevent="submitSearch">
          <input
            v-model="search"
            type="search"
            placeholder="Rechercher un événement, un artiste, une ville…"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
          >
        </form>

        <nav class="flex items-center gap-4 text-sm">
          <NuxtLink to="/events" class="text-slate-600 hover:text-brand-700">
            Événements
          </NuxtLink>
          <ClientOnly>
            <template v-if="isAuthenticated">
              <NuxtLink to="/account/tickets" class="text-slate-600 hover:text-brand-700">
                Mes billets
              </NuxtLink>
              <NuxtLink
                v-if="isOrganizer"
                to="/organizer/dashboard"
                class="text-slate-600 hover:text-brand-700"
              >
                Espace organisateur
              </NuxtLink>
              <button
                type="button"
                class="text-slate-600 hover:text-brand-700"
                :title="user?.email ?? ''"
                @click="logout"
              >
                Déconnexion
              </button>
            </template>
            <template #fallback>
              <NuxtLink to="/login" class="text-slate-600 hover:text-brand-700">
                Connexion
              </NuxtLink>
            </template>
            <template v-if="!isAuthenticated">
              <NuxtLink to="/login" class="text-slate-600 hover:text-brand-700">
                Connexion
              </NuxtLink>
              <NuxtLink
                to="/register"
                class="rounded-md bg-brand-600 px-3 py-2 text-white hover:bg-brand-700"
              >
                Inscription
              </NuxtLink>
            </template>
          </ClientOnly>
        </nav>
      </div>
    </header>

    <main class="flex-1">
      <slot />
    </main>

    <footer class="border-t border-slate-200 py-6 text-sm text-slate-500">
      <div class="mx-auto max-w-7xl px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
        <p>© Resonance — fil rouge de formation perf web.</p>
        <p class="text-xs">Starter intentionnellement non-optimisé · cf. <code>resonance-spec.md</code> §8.</p>
      </div>
    </footer>
  </div>
</template>
