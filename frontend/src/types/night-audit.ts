// ──────────────────────────────────────────────────────────────
//  Types Night Audit — Sprint 13quater-C
// ──────────────────────────────────────────────────────────────

export interface NightAuditCurrent {
  businessDate: string         // 'YYYY-MM-DD'
  lastCloseDate: string | null
  canClose: boolean
  reason: string | null
  alreadyClosed: boolean
}

export interface NightAuditWarning {
  code: string
  severity: 'warning' | 'critical'
  label: string
  message: string
  count: number
  details?: Array<Record<string, unknown>>
}

export interface NightAuditChecklist {
  businessDate: string
  warnings: NightAuditWarning[]
  canCloseClean: boolean
}

export interface DailyCloseSnapshot {
  kpis: {
    occupancyRate?: number | string
    adrHtXof?: string
    revparHtXof?: string
    revenueHtXof?: string
    revenueTtcXof?: string
    soldNights?: number
    availableNights?: number
    occupiedRooms?: number
    availableRooms?: number
  }
  counts: {
    arrivals?: number
    departures?: number
  }
  cash: {
    byMethod: Record<string, string>
    totalXof: string
  }
  invoices: {
    issued: number
    totalXof: string
  }
  rooms: Array<{
    id: string
    number: string
    status: string
  }>
  warnings: NightAuditWarning[]
  meta?: {
    businessDate: string
    generatedAt: string
  }
}

export interface DailyClose {
  id: string
  businessDate: string  // ISO datetime ATOM (back retourne le full datetime)
  closedAt: string
  closedById: string
  closedByEmail: string
  cutoffHour: number
  snapshot: DailyCloseSnapshot
  reopenedAt: string | null
  reopenedById: string | null
  reopenedByEmail: string | null
  reopenReason: string | null
  createdAt: string
}

export interface DailyCloseListResponse {
  data: DailyClose[]   // sans snapshot pour la liste (groupe night_audit:read)
  meta: { total: number; page: number; perPage: number; pages: number }
}
