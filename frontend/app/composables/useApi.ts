import type { $Fetch, FetchOptions } from 'ofetch'
import { useAuthStore } from '~/stores/auth'

/**
 * Fournit un `$fetch`-like préconfiguré pour l'API Resonance :
 *   - choix automatique de l'URL (apiBaseInternal côté SSR, apiBase côté client) ;
 *   - injection du Bearer token Sanctum stocké dans Pinia ;
 *   - auto-logout sur 401 (clear store + clear localStorage + redirect /login).
 *
 * @perf-debt: aucun cache navigateur côté front (pas d'`useFetch`/`useAsyncData`
 * memoizé), donc chaque mount d'une page refetch toutes ses données. Résolu en
 * J1/J2 selon la ressource (CDN cache + ISR pour les pages publiques, cache
 * applicatif pour les KPIs organizer).
 */
export function useApi(): $Fetch {
  const config = useRuntimeConfig()
  const auth = useAuthStore()

  const baseURL = import.meta.server
    ? config.apiBaseInternal
    : config.public.apiBase

  return $fetch.create({
    baseURL,
    onRequest({ options }) {
      if (auth.token) {
        const headers = new Headers(options.headers as HeadersInit | undefined)
        headers.set('Authorization', `Bearer ${auth.token}`)
        headers.set('Accept', 'application/json')
        options.headers = headers
      }
      else {
        const headers = new Headers(options.headers as HeadersInit | undefined)
        headers.set('Accept', 'application/json')
        options.headers = headers
      }
    },
    onResponseError({ response }) {
      // Auto-logout : si l'API renvoie 401, on considère le token expiré ou
      // révoqué côté Sanctum. Clear store + redirect /login (côté client
      // uniquement — côté SSR on laisse l'erreur remonter pour 404/500).
      if (response?.status === 401 && import.meta.client && auth.isAuthenticated) {
        auth.clear()
        navigateTo('/login')
      }
    },
  }) as $Fetch
}

/** Helper pratique : exécute une requête en passant directement les options. */
export async function apiFetch<T = unknown>(
  url: string,
  options?: FetchOptions,
): Promise<T> {
  const api = useApi()
  return api<T>(url, options as never)
}
