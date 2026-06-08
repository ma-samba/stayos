import axios, { type AxiosInstance } from 'axios'
import type {
  CreateTenantPayload,
  CreateTenantResponse,
  ForcePlanPayload,
  PlatformMetrics,
  SuperAdminAuditListParams,
  SuperAdminAuditResponse,
  TenantDetail,
  TenantSummary,
  TenantsListResponse,
  UpdateTenantPayload,
} from '@/types/superadmin'

// ──────────────────────────────────────────────────────────────
//  Instance axios dédiée SuperAdmin.
//
//  Volontairement séparée de api.service.ts (instance staff) pour :
//   - éviter d'envoyer X-Tenant-Slug sur les routes /superadmin
//   - utiliser un token distinct (localStorage 'superadmin_token')
//   - ne pas déclencher le refresh-token du staff sur un 401
// ──────────────────────────────────────────────────────────────

const superadminApi: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_SUPERADMIN_URL ?? '',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  timeout: 15_000,
})

superadminApi.interceptors.request.use((config) => {
  const token = localStorage.getItem('superadmin_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

superadminApi.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('superadmin_token')
      try {
        const router = (await import('@/router')).default
        if (!router.currentRoute.value.path.startsWith('/superadmin/login')) {
          await router.push('/superadmin/login')
        }
      } catch {
        /* noop */
      }
    }
    return Promise.reject(error)
  },
)

// ──────────────────────────────────────────────────────────────
//  Méthodes
// ──────────────────────────────────────────────────────────────

export interface ListTenantsParams {
  page?: number
  perPage?: number
  status?: string
  plan?: string
  search?: string
}

export const superadminService = {
  async login(email: string, password: string): Promise<{ token: string }> {
    const { data } = await superadminApi.post<{ token: string }>(
      '/superadmin/auth/login',
      { email, password },
    )
    return data
  },

  async listTenants(params: ListTenantsParams = {}): Promise<TenantsListResponse> {
    const { data } = await superadminApi.get<TenantsListResponse>('/superadmin/tenants', {
      params,
    })
    return data
  },

  async getTenant(slug: string): Promise<TenantDetail> {
    const { data } = await superadminApi.get<{ data: TenantDetail }>(
      `/superadmin/tenants/${encodeURIComponent(slug)}`,
    )
    return data.data
  },

  async suspendTenant(slug: string, reason?: string): Promise<TenantSummary> {
    const { data } = await superadminApi.post<{ data: TenantSummary }>(
      `/superadmin/tenants/${encodeURIComponent(slug)}/suspend`,
      reason ? { reason } : {},
    )
    return data.data
  },

  async reactivateTenant(slug: string): Promise<TenantSummary> {
    const { data } = await superadminApi.post<{ data: TenantSummary }>(
      `/superadmin/tenants/${encodeURIComponent(slug)}/reactivate`,
    )
    return data.data
  },

  async getMetrics(): Promise<PlatformMetrics> {
    const { data } = await superadminApi.get<{ data: PlatformMetrics }>(
      '/superadmin/metrics',
    )
    return data.data
  },

  // ── Sprint 13bis-B ────────────────────────────────────────

  async createTenant(payload: CreateTenantPayload): Promise<CreateTenantResponse> {
    const { data } = await superadminApi.post<{ data: CreateTenantResponse }>(
      '/superadmin/tenants',
      payload,
    )
    return data.data
  },

  async updateTenant(slug: string, payload: UpdateTenantPayload): Promise<TenantSummary> {
    const { data } = await superadminApi.patch<{ data: TenantSummary }>(
      `/superadmin/tenants/${encodeURIComponent(slug)}`,
      payload,
    )
    return data.data
  },

  async forcePlan(slug: string, payload: ForcePlanPayload): Promise<TenantSummary> {
    const { data } = await superadminApi.post<{ data: TenantSummary }>(
      `/superadmin/tenants/${encodeURIComponent(slug)}/force-plan`,
      payload,
    )
    return data.data
  },

  async listAudit(params: SuperAdminAuditListParams = {}): Promise<SuperAdminAuditResponse> {
    const { data } = await superadminApi.get<SuperAdminAuditResponse>(
      '/superadmin/audit',
      { params },
    )
    return data
  },
}

export default superadminApi
