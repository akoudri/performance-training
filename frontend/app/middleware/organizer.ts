import { useAuthStore } from '~/stores/auth'

/**
 * Restreint l'accès aux pages /organizer/* aux utilisateurs avec
 * `role === 'organizer'`. À utiliser avec le middleware `auth` (pas
 * indépendamment) :
 *
 *   definePageMeta({ middleware: ['auth', 'organizer'] })
 */
export default defineNuxtRouteMiddleware(() => {
  const auth = useAuthStore()

  if (import.meta.server) return

  if (!auth.isAuthenticated) {
    return navigateTo('/login')
  }

  if (!auth.isOrganizer) {
    return navigateTo('/')
  }
})
