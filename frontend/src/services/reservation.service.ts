import api from './api.service'
import type {
  Reservation,
  ReservationGantt,
  CreateReservationPayload,
  UpdateReservationPayload,
  ApiSuccess,
} from '@/types/entities'
import type {
  CancellationQuote,
  CancelResult,
  NoShowPolicy,
  NoShowResult,
} from '@/types/financial-policies'

// ──────────────────────────────────────────────────────────────
//  Service Réservations
// ──────────────────────────────────────────────────────────────

export const reservationService = {
  /**
   * GET /api/reservations — Liste avec filtres optionnels.
   */
  async getAll(params?: { status?: string; from?: string; to?: string }): Promise<Reservation[]> {
    const { data } = await api.get<ApiSuccess<Reservation[]>>('/reservations', { params })
    return data.data
  },

  /**
   * GET /api/reservations/gantt?from=&to= — Planning Gantt.
   */
  async getGantt(from: string, to: string): Promise<ReservationGantt[]> {
    const { data } = await api.get<ApiSuccess<ReservationGantt[]>>('/reservations/gantt', {
      params: { from, to },
    })
    return data.data
  },

  /**
   * GET /api/reservations/{id} — Détail.
   */
  async getOne(id: string): Promise<Reservation> {
    const { data } = await api.get<ApiSuccess<Reservation>>(`/reservations/${id}`)
    return data.data
  },

  /**
   * POST /api/reservations — Créer.
   */
  async create(payload: CreateReservationPayload): Promise<Reservation> {
    const { data } = await api.post<ApiSuccess<Reservation>>('/reservations', payload)
    return data.data
  },

  /**
   * PUT /api/reservations/{id} — Modifier.
   */
  async update(id: string, payload: UpdateReservationPayload): Promise<Reservation> {
    const { data } = await api.put<ApiSuccess<Reservation>>(`/reservations/${id}`, payload)
    return data.data
  },

  /**
   * POST /api/reservations/{id}/checkin
   */
  async checkIn(id: string, notes?: string): Promise<Reservation> {
    const { data } = await api.post<ApiSuccess<Reservation>>(`/reservations/${id}/checkin`, {
      notes: notes ?? null,
    })
    return data.data
  },

  /**
   * POST /api/reservations/{id}/checkout
   */
  async checkOut(id: string): Promise<Reservation> {
    const { data } = await api.post<ApiSuccess<Reservation>>(`/reservations/${id}/checkout`)
    return data.data
  },

  /**
   * POST /api/reservations/{id}/cancel — Sprint 13quinquies :
   * applique la politique d'annulation tenant et émet une facture
   * de frais si le montant > 0. `feeOverrideXof` est un geste
   * commercial (tracé dans l'audit log).
   */
  async cancel(
    id: string,
    reason: string,
    feeOverrideXof?: string,
  ): Promise<CancelResult> {
    const { data } = await api.post<ApiSuccess<CancelResult>>(
      `/reservations/${id}/cancel`,
      {
        reason,
        feeOverrideXof: feeOverrideXof ?? null,
      },
    )
    return data.data
  },

  /**
   * GET /api/reservations/{id}/cancellation-quote — Sprint 13quinquies.
   * Calcule les frais sans modifier la réservation. Utilisé par la
   * modal d'annulation pour pré-remplir le montant.
   */
  async getCancellationQuote(id: string): Promise<CancellationQuote> {
    const { data } = await api.get<ApiSuccess<CancellationQuote>>(
      `/reservations/${id}/cancellation-quote`,
    )
    return data.data
  },

  /**
   * POST /api/reservations/{id}/no-show — Sprint 13quinquies.
   * Marque la réservation NO_SHOW. La politique tenant s'applique
   * par défaut ; le réceptionniste peut surcharger via `policyOverride`.
   */
  async markNoShow(
    id: string,
    policyOverride?: NoShowPolicy,
  ): Promise<NoShowResult> {
    const { data } = await api.post<ApiSuccess<NoShowResult>>(
      `/reservations/${id}/no-show`,
      {
        policy: policyOverride ?? null,
      },
    )
    return data.data
  },
}
