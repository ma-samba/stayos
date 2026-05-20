import api from './api.service'
import type {
  Reservation,
  ReservationGantt,
  CreateReservationPayload,
  UpdateReservationPayload,
  ApiSuccess,
} from '@/types/entities'

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
   * POST /api/reservations/{id}/cancel
   */
  async cancel(id: string, reason: string): Promise<Reservation> {
    const { data } = await api.post<ApiSuccess<Reservation>>(`/reservations/${id}/cancel`, {
      reason,
    })
    return data.data
  },
}
