import { defineStore } from 'pinia'
import type { User } from '~/types/auth'

const STORAGE_KEY = 'resonance:auth'

interface PersistedAuth {
  token: string
  user: User
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: null as string | null,
    user: null as User | null,
  }),

  getters: {
    isAuthenticated: (state): boolean => Boolean(state.token && state.user),
    isOrganizer: (state): boolean => state.user?.role === 'organizer',
  },

  actions: {
    setSession(token: string, user: User) {
      this.token = token
      this.user = user
      this.persist()
    },

    clear() {
      this.token = null
      this.user = null
      if (import.meta.client) {
        localStorage.removeItem(STORAGE_KEY)
      }
    },

    persist() {
      if (!import.meta.client) return
      if (!this.token || !this.user) return
      const payload: PersistedAuth = { token: this.token, user: this.user }
      localStorage.setItem(STORAGE_KEY, JSON.stringify(payload))
    },

    restoreFromStorage() {
      if (!import.meta.client) return
      const raw = localStorage.getItem(STORAGE_KEY)
      if (!raw) return
      try {
        const data = JSON.parse(raw) as PersistedAuth
        if (data?.token && data?.user) {
          this.token = data.token
          this.user = data.user
        }
      }
      catch {
        localStorage.removeItem(STORAGE_KEY)
      }
    },
  },
})
