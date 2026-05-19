<script setup lang="ts">
import { useAuth } from '~/composables/useAuth'

definePageMeta({ layout: 'default' })
useHead({ title: 'Inscription · Resonance' })

const { register } = useAuth()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const error = ref<string | null>(null)
const loading = ref(false)

async function submit() {
  error.value = null
  loading.value = true
  try {
    await register({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    await navigateTo('/')
  }
  catch (e: unknown) {
    const err = e as { data?: { message?: string, errors?: Record<string, string[]> } }
    if (err?.data?.errors) {
      error.value = Object.values(err.data.errors).flat().join(' · ')
    }
    else {
      error.value = err?.data?.message ?? 'Erreur lors de l\'inscription.'
    }
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-md px-4 py-12">
    <h1 class="text-2xl font-bold mb-6">
      Inscription
    </h1>
    <form class="space-y-4" @submit.prevent="submit">
      <div>
        <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Nom</label>
        <input
          id="name"
          v-model="name"
          type="text"
          required
          autocomplete="name"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
      </div>
      <div>
        <label for="email" class="block text-sm font-medium text-slate-700 mb-1">Email</label>
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
        <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Mot de passe</label>
        <input
          id="password"
          v-model="password"
          type="password"
          required
          minlength="8"
          autocomplete="new-password"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
      </div>
      <div>
        <label for="password_confirmation" class="block text-sm font-medium text-slate-700 mb-1">
          Confirmation
        </label>
        <input
          id="password_confirmation"
          v-model="passwordConfirmation"
          type="password"
          required
          minlength="8"
          autocomplete="new-password"
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
        {{ loading ? 'Inscription…' : 'Créer mon compte' }}
      </button>
    </form>

    <p class="mt-6 text-sm text-slate-500">
      Déjà un compte ?
      <NuxtLink to="/login" class="text-brand-700 hover:underline">
        Connexion
      </NuxtLink>
    </p>
  </section>
</template>
