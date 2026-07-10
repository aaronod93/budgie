import { defineStore } from 'pinia'

export interface User {
  id: number
  name: string
  email: string
  email_verified_at: string | null
  two_factor_confirmed_at?: string | null
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  // True once we've asked the API who we are (even if the answer was "nobody").
  const loaded = ref(false)

  async function ensureCsrf(): Promise<void> {
    await apiFetch('/sanctum/csrf-cookie')
  }

  async function fetchUser(): Promise<void> {
    try {
      user.value = await apiFetch<User>('/api/user')
    } catch {
      user.value = null
    } finally {
      loaded.value = true
    }
  }

  async function login(email: string, password: string): Promise<{ twoFactor: boolean }> {
    await ensureCsrf()
    const response = await apiFetch<{ two_factor: boolean }>('/login', {
      method: 'POST',
      body: { email, password },
    })
    if (response.two_factor) return { twoFactor: true }
    await fetchUser()
    return { twoFactor: false }
  }

  async function twoFactorChallenge(payload: { code?: string; recovery_code?: string }): Promise<void> {
    await apiFetch('/two-factor-challenge', { method: 'POST', body: payload })
    await fetchUser()
  }

  async function register(payload: {
    name: string
    email: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    await ensureCsrf()
    await apiFetch('/register', { method: 'POST', body: payload })
    await fetchUser()
  }

  async function logout(): Promise<void> {
    await apiFetch('/logout', { method: 'POST' })
    user.value = null
  }

  return { user, loaded, fetchUser, login, twoFactorChallenge, register, logout }
})
