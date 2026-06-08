// ──────────────────────────────────────────────────────────────
//  Types module Personnel — Sprint 13bis
// ──────────────────────────────────────────────────────────────

export type StaffRole = 'MANAGER' | 'RECEPTIONIST' | 'ACCOUNTANT' | 'HOUSEKEEPER'

export type InvitationStatus = 'pending' | 'accepted' | 'expired' | 'revoked'

export interface StaffMember {
  id: string
  email: string
  firstName: string
  lastName: string
  fullName: string
  role: string                 // valeur courte ('MANAGER', etc.) sérialisée par l'entité
  roles?: string[]             // ['ROLE_MANAGER', 'ROLE_USER'] côté API
  phone: string | null
  active: boolean
  locale?: string
  lastLoginAt?: string | null
  createdAt?: string
}

export interface StaffInvitation {
  id: string
  email: string
  firstName: string
  lastName: string
  role: string
  status: InvitationStatus
  expiresAt: string
  createdAt: string
  acceptedAt: string | null
  revokedAt: string | null
  invitedBy: string | null
}

export interface CreateStaffPayload {
  email: string
  firstName: string
  lastName: string
  role: StaffRole
  phone?: string | null
}

export interface UpdateStaffPayload {
  firstName?: string
  lastName?: string
  role?: StaffRole
  phone?: string | null
}

export interface CreateInvitationPayload {
  email: string
  firstName: string
  lastName: string
  role: StaffRole
}

export interface CreateStaffResponse {
  id: string
  email: string
  firstName: string
  lastName: string
  role: string
  phone: string | null
  active: boolean
  tempPassword: string
}

export interface PublicInvitationInfo {
  email: string
  firstName: string
  lastName: string
  role: string
  expiresAt: string
}

export interface StaffAuditEntry {
  id: string
  action: string
  staffUserEmail: string | null
  staffUserRole: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  createdAt: string
}

export interface StaffActivityEntry {
  id: string
  action: string
  entityType: string
  entityId: string
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  createdAt: string
}
