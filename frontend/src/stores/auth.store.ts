import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

interface JwtClaims {
  sub: string
  uid: string
  slug: string
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
  const userId          = computed(() => claims.value?.uid ?? null)
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

    // Persister le slug tenant pour le header X-Tenant-Slug
    if (claims.value?.slug) {
      localStorage.setItem('tenant_slug', claims.value.slug)
    }
  }

  function logout(): void {
    token.value        = null
    refreshToken.value = null
    claims.value       = null

    localStorage.removeItem('token')
    localStorage.removeItem('refresh_token')
    localStorage.removeItem('tenant_slug')
  }

  function hasFeature(feature: string): boolean {
    return features.value.includes(feature)
  }

  // ── RBAC par module ──

  const MODULE_ACCESS: Record<string, string[]> = {
    dashboard:    ['MANAGER', 'RECEPTIONIST', 'ACCOUNTANT'],
    rooms:        ['MANAGER', 'RECEPTIONIST', 'ACCOUNTANT'],
    guests:       ['MANAGER', 'RECEPTIONIST'],
    reservations: ['MANAGER', 'RECEPTIONIST'],
    billing:      ['MANAGER', 'RECEPTIONIST', 'ACCOUNTANT'],
    housekeeping: ['MANAGER', 'RECEPTIONIST', 'HOUSEKEEPER'],
    rates:        ['MANAGER'],
  }

  function canAccess(module: string): boolean {
    const role = claims.value?.role
    if (!role) return false
    return MODULE_ACCESS[module]?.includes(role) ?? false
  }

  function firstAccessiblePath(): string {
    if (canAccess('dashboard')) return '/dashboard'
    if (canAccess('rooms')) return '/rooms'
    if (canAccess('reservations')) return '/reservations'
    if (canAccess('billing')) return '/invoices'
    if (canAccess('housekeeping')) return '/housekeeping'
    if (canAccess('rates')) return '/rates'
    return '/login'
  }

  return {
    token,
    refreshToken,
    claims,
    isAuthenticated,
    userId,
    tenantId,
    userRole,
    hotelName,
    features,
    setTokens,
    logout,
    hasFeature,
    canAccess,
    firstAccessiblePath,
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
