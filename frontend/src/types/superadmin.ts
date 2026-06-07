// ──────────────────────────────────────────────────────────────
//  Types SuperAdmin (back-office plateforme)
//  Alignés sur SuperAdminController + PlatformMetricsService côté
//  backend (sprint 13a).
// ──────────────────────────────────────────────────────────────

export type TenantStatus = 'trial' | 'active' | 'suspended' | 'churned'

export interface TenantSummary {
  id: string
  slug: string
  name: string
  status: TenantStatus
  subdomain: string
  country: string
  currency: string
  createdAt: string
  subscriptionStatus: string | null
  planName: string | null
}

export interface TenantSubscription {
  id: string
  status: string
  billingCycle: string
  plan: string
  trialEndsAt: string | null
  currentPeriodStart: string | null
  currentPeriodEnd: string | null
  cancelledAt: string | null
}

export interface TenantInvoice {
  id: string
  number: string
  planName: string
  amountXof: string
  status: string
  dueAt: string | null
  paidAt: string | null
  createdAt: string
}

export interface TenantDetail extends TenantSummary {
  subscription: TenantSubscription | null
  recentInvoices: TenantInvoice[]
}

export interface PlatformMetrics {
  // mrr est une décimale au format string (bcmath côté backend).
  mrr: string
  activeTenantsCount: number
  trialTenantsCount: number
  suspendedTenantsCount: number
  // Tenants en statut CHURNED côté backend, libellés « désabonnés » dans l'UI.
  cancelledTenantsCount: number
  newTenantsLast30Days: number
  churnLast30Days: number
  planDistribution: {
    STARTER: number
    PRO: number
    ENTERPRISE: number
  }
}

export interface TenantsListMeta {
  total: number
  page: number
  perPage: number
  pages: number
}

export interface TenantsListResponse {
  data: TenantSummary[]
  meta: TenantsListMeta
}

export interface SuperAdminJwtClaims {
  username: string
  roles: string[]
  exp: number
  iat?: number
}
