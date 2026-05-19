import { useAuthStore } from '~/stores/auth'

/**
 * Bloque l'accès aux pages qui requièrent une session active. Le store est
 * restauré depuis localStorage par le plugin `auth.client.ts` avant que ce
 * middleware ne s'exécute (côté client).
 *
 * Usage : `definePageMeta({ middleware: 'auth' })`.
 */
export default defineNuxtRouteMiddleware((to) => {
  const auth = useAuthStore()

  if (import.meta.server) {
    // Pas de session côté SSR (token uniquement côté client). On laisse
    // passer ; la redirection sera effectuée côté client si besoin.
    return
  }

  if (!auth.isAuthenticated) {
    return navigateTo({
      path: '/login',
      query: { redirect: to.fullPath },
    })
  }
})
