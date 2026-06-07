import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { SuperAdminJwtClaims } from '@/types/superadmin'

// ──────────────────────────────────────────────────────────────
//  Store SuperAdmin — strictement isolé de useAuthStore (staff).
//
//  Le token SuperAdmin contient des claims minimaux (username,
//  roles, exp) — pas de tenant, pas de plan, pas de features.
//  Stocké dans localStorage 'superadmin_token' pour ne pas
//  interférer avec 'token' du staff.
// ──────────────────────────────────────────────────────────────

const STORAGE_KEY = 'superadmin_token'

export const useSuperAdminStore = defineStore('superadmin', () => {
  const token  = ref<string | null>(localStorage.getItem(STORAGE_KEY))
  const claims = ref<SuperAdminJwtClaims | null>(parseClaims(token.value))

  const isAuthenticated = computed(() => !!token.value && !isExpired(claims.value))
  const isSuperAdmin = computed(
    () => claims.value?.roles?.includes('ROLE_SUPER_ADMIN') ?? false,
  )
  const username = computed(() => claims.value?.username ?? null)

  function setToken(newToken: string): void {
    token.value  = newToken
    claims.value = parseClaims(newToken)
    localStorage.setItem(STORAGE_KEY, newToken)
  }

  function logout(): void {
    token.value  = null
    claims.value = null
    localStorage.removeItem(STORAGE_KEY)
  }

  return {
    token,
    claims,
    isAuthenticated,
    isSuperAdmin,
    username,
    setToken,
    logout,
  }
})

// ──────────────────────────────────────────────────────────────
//  Helpers
// ──────────────────────────────────────────────────────────────

function parseClaims(token: string | null): SuperAdminJwtClaims | null {
  if (!token) return null
  try {
    const payload = token.split('.')[1]
    return JSON.parse(atob(payload)) as SuperAdminJwtClaims
  } catch {
    return null
  }
}

function isExpired(claims: SuperAdminJwtClaims | null): boolean {
  if (!claims?.exp) return false
  return Date.now() / 1000 >= claims.exp
}
