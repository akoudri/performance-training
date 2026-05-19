export interface User {
  id: number
  name: string
  email: string
  role: 'visitor' | 'organizer'
  email_verified_at?: string | null
  created_at?: string
  updated_at?: string
}

export interface AuthSession {
  token: string
  user: User
}

export interface LoginPayload {
  email: string
  password: string
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
}
