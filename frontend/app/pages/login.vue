<script setup lang="ts">
import { useAuth } from '~/composables/useAuth'

definePageMeta({ layout: 'default' })
useHead({ title: 'Connexion · Resonance' })

const { login } = useAuth()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)
const route = useRoute()

async function submit() {
  error.value = null
  loading.value = true
  try {
    const user = await login({ email: email.value, password: password.value })
    const redirect = (route.query.redirect as string) || (
      user.role === 'organizer' ? '/organizer/dashboard' : '/'
    )
    await navigateTo(redirect)
  }
  catch (e: unknown) {
    const err = e as { data?: { message?: string }, statusCode?: number }
    error.value = err?.data?.message
      ?? (err?.statusCode === 422 ? 'Identifiants invalides.' : 'Erreur lors de la connexion.')
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-md px-4 py-12">
    <h1 class="text-2xl font-bold mb-6">
      Connexion
    </h1>
    <form class="space-y-4" @submit.prevent="submit">
      <div>
        <label for="email" class="block text-sm font-medium text-slate-700 mb-1">
          Email
        </label>
        <input
          id="email"
          v-model="email"
          type="email"
          required
          autocomplete="email"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
      </div>
      <div>
        <label for="password" class="block text-sm font-medium text-slate-700 mb-1">
          Mot de passe
        </label>
        <input
          id="password"
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
      </div>

      <p v-if="error" class="text-sm text-red-600">
        {{ error }}
      </p>

      <button
        type="submit"
        :disabled="loading"
        class="w-full rounded-md bg-brand-600 px-3 py-2 text-white font-medium hover:bg-brand-700 disabled:opacity-50"
      >
        {{ loading ? 'Connexion…' : 'Se connecter' }}
      </button>
    </form>

    <p class="mt-6 text-sm text-slate-500">
      Pas encore de compte ?
      <NuxtLink to="/register" class="text-brand-700 hover:underline">
        Inscription
      </NuxtLink>
    </p>

    <div class="mt-8 rounded-md border border-slate-200 bg-slate-50 p-4 text-xs text-slate-500">
      <p class="font-semibold mb-1">
        Comptes de démo (mot de passe : <code>password</code>) :
      </p>
      <ul class="space-y-0.5">
        <li>visitor@demo.test — visitor</li>
        <li>organizer@demo.test — organizer</li>
      </ul>
    </div>
  </section>
</template>
