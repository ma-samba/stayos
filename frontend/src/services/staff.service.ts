import axios, { type AxiosInstance } from 'axios'
import api from '@/services/api.service'
import { resolveTenantSlug } from '@/services/tenant'
import type {
  CreateInvitationPayload,
  CreateStaffPayload,
  CreateStaffResponse,
  PublicInvitationInfo,
  StaffActivityEntry,
  StaffAuditEntry,
  StaffInvitation,
  StaffMember,
  UpdateStaffPayload,
} from '@/types/staff'

// ──────────────────────────────────────────────────────────────
//  Instance axios publique pour les endpoints d'invitation
//  (/public/invitations/...) — pas de JWT, pas de redirect 401
//  sur /login.
//
//  En dev local : passe par le proxy Vite (cf. vite.config.ts).
//  Le slug tenant arrive via le header X-Tenant-Slug (résolu
//  depuis le hostname courant) ou via le subdomain en prod.
// ──────────────────────────────────────────────────────────────

const publicApi: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_URL?.replace(/\/api\/?$/, '') ?? '',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  timeout: 15_000,
})

publicApi.interceptors.request.use((config) => {
  const slug = resolveTenantSlug()
  if (slug) {
    config.headers['X-Tenant-Slug'] = slug
  }
  return config
})

// ──────────────────────────────────────────────────────────────
//  Méthodes — staff (authentifié, ROLE_MANAGER pour write)
// ──────────────────────────────────────────────────────────────

function unwrap<T>(promise: Promise<{ data: { data: T } }>): Promise<T> {
  return promise.then((r) => r.data.data)
}

export const staffService = {
  listStaff(role?: string): Promise<StaffMember[]> {
    return unwrap(api.get('/staff', { params: role ? { role } : {} }))
  },

  createStaff(payload: CreateStaffPayload): Promise<CreateStaffResponse> {
    return unwrap(api.post('/staff', payload))
  },

  updateStaff(id: string, payload: UpdateStaffPayload): Promise<StaffMember> {
    return unwrap(api.put(`/staff/${id}`, payload))
  },

  resetPassword(id: string): Promise<{ tempPassword: string }> {
    return unwrap(api.post(`/staff/${id}/reset-password`))
  },

  deactivateStaff(id: string): Promise<StaffMember> {
    return unwrap(api.delete(`/staff/${id}`))
  },

  reactivateStaff(id: string): Promise<StaffMember> {
    return unwrap(api.post(`/staff/${id}/reactivate`))
  },

  getStaffAudit(id: string): Promise<StaffAuditEntry[]> {
    return unwrap(api.get(`/staff/${id}/audit`))
  },

  getStaffActivity(id: string): Promise<StaffActivityEntry[]> {
    return unwrap(api.get(`/staff/${id}/activity`))
  },

  // ── Invitations (manager) ───────────────────────────────────
  listInvitations(status?: string): Promise<StaffInvitation[]> {
    return unwrap(api.get('/staff/invitations', { params: status ? { status } : {} }))
  },

  createInvitation(payload: CreateInvitationPayload): Promise<StaffInvitation> {
    return unwrap(api.post('/staff/invitations', payload))
  },

  revokeInvitation(id: string): Promise<StaffInvitation> {
    return unwrap(api.post(`/staff/invitations/${id}/revoke`))
  },

  // ── Invitations (public, acceptation) ───────────────────────
  async getInvitationByToken(token: string): Promise<PublicInvitationInfo> {
    const { data } = await publicApi.get<{ data: PublicInvitationInfo }>(
      `/public/invitations/${encodeURIComponent(token)}`,
    )
    return data.data
  },

  async acceptInvitation(token: string, password: string): Promise<{ email: string; role: string }> {
    const { data } = await publicApi.post<{ data: { email: string; role: string } }>(
      `/public/invitations/${encodeURIComponent(token)}/accept`,
      { password },
    )
    return data.data
  },
}
