// ──────────────────────────────────────────────────────────────
//  Politiques financières — Sprint 13quinquies-A
// ──────────────────────────────────────────────────────────────

import type { Invoice, Reservation } from './entities'

export type NoShowPolicy = 'none' | 'first_night' | 'full'
export type CancellationPolicy = 'flexible' | 'moderate' | 'strict'

export interface TenantSettings {
  noShowPolicy: NoShowPolicy
  cancellationPolicy: CancellationPolicy
  businessDayCutoffHour: number
  timezone: string
  currency: string
}

export interface CancellationQuote {
  policy: CancellationPolicy
  amountXof: string
  reason: string
  hoursBefore: number
}

export interface NoShowResult {
  reservation: Reservation
  invoice: Invoice | null
  policy: NoShowPolicy
  feeXof: string
}

export interface CancelResult {
  reservation: Reservation
  invoice: Invoice | null
  feeXof: string
  feeQuote: CancellationQuote
}
