import { useAuthStore } from '~/stores/auth'

/**
 * Restaure la session Sanctum depuis localStorage au boot client.
 * S'exécute UNIQUEMENT côté navigateur (suffixe `.client`) — la session SSR
 * est intentionnellement anonyme : les pages publiques sont rendues côté
 * serveur sans contexte utilisateur, et les pages protégées passent par le
 * middleware `auth` qui s'exécute après ce plugin.
 */
export default defineNuxtPlugin(() => {
  const auth = useAuthStore()
  auth.restoreFromStorage()
})
