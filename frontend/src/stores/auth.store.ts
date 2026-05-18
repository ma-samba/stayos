import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

interface JwtClaims {
  sub: string
  tenant: string
  role: string
  plan: string
  features: string[]
  hotel: string
  exp: number
}

// ──────────────────────────────────────────────────────────────
//  Store Authentification
// ──────────────────────────────────────────────────────────────

export const useAuthStore = defineStore('auth', () => {
  const token        = ref<string | null>(localStorage.getItem('token'))
  const refreshToken = ref<string | null>(localStorage.getItem('refresh_token'))
  const claims       = ref<JwtClaims | null>(parseClaims(token.value))

  const isAuthenticated = computed(() => !!token.value)
  const tenantId        = computed(() => claims.value?.tenant ?? null)
  const userRole        = computed(() => claims.value?.role ?? null)
  const hotelName       = computed(() => claims.value?.hotel ?? null)
  const features        = computed(() => claims.value?.features ?? [])

  function setTokens(newToken: string, newRefresh: string): void {
    token.value        = newToken
    refreshToken.value = newRefresh
    claims.value       = parseClaims(newToken)

    localStorage.setItem('token', newToken)
    localStorage.setItem('refresh_token', newRefresh)
  }

  function logout(): void {
    token.value        = null
    refreshToken.value = null
    claims.value       = null

    localStorage.removeItem('token')
    localStorage.removeItem('refresh_token')
  }

  function hasFeature(feature: string): boolean {
    return features.value.includes(feature)
  }

  return {
    token,
    refreshToken,
    claims,
    isAuthenticated,
    tenantId,
    userRole,
    hotelName,
    features,
    setTokens,
    logout,
    hasFeature,
  }
})

// ──────────────────────────────────────────────────────────────
//  Helpers
// ──────────────────────────────────────────────────────────────

function parseClaims(token: string | null): JwtClaims | null {
  if (!token) return null

  try {
    const payload = token.split('.')[1]
    return JSON.parse(atob(payload)) as JwtClaims
  } catch {
    return null
  }
}
