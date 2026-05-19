import { useAuthStore } from '~/stores/auth'
import type { AuthSession, LoginPayload, RegisterPayload, User } from '~/types/auth'

/**
 * API d'authentification orientée Bearer token (Sanctum mode token, cf.
 * resonance-spec.md §2 / §6). Les tokens sont stockés dans Pinia + localStorage
 * pour persister au reload (XSS-trade-off accepté pour un projet pédagogique).
 */
export function useAuth() {
  const auth = useAuthStore()
  const api = useApi()

  async function login(payload: LoginPayload): Promise<User> {
    const response = await api<{ data: AuthSession }>('/auth/login', {
      method: 'POST',
      body: payload,
    })
    auth.setSession(response.data.token, response.data.user)
    return response.data.user
  }

  async function register(payload: RegisterPayload): Promise<User> {
    const response = await api<{ data: AuthSession }>('/auth/register', {
      method: 'POST',
      body: payload,
    })
    auth.setSession(response.data.token, response.data.user)
    return response.data.user
  }

  async function logout(): Promise<void> {
    if (auth.isAuthenticated) {
      try {
        await api('/auth/logout', { method: 'POST' })
      }
      catch {
        // 401 / réseau : on ignore, on clear quand même côté client.
      }
    }
    auth.clear()
    if (import.meta.client) {
      await navigateTo('/login')
    }
  }

  async function fetchMe(): Promise<User | null> {
    if (!auth.token) return null
    try {
      const data = await api<{ data: User }>('/auth/me')
      auth.user = data.data
      auth.persist()
      return auth.user
    }
    catch {
      return null
    }
  }

  return {
    user: computed(() => auth.user),
    token: computed(() => auth.token),
    isAuthenticated: computed(() => auth.isAuthenticated),
    isOrganizer: computed(() => auth.isOrganizer),
    login,
    register,
    logout,
    fetchMe,
  }
}
