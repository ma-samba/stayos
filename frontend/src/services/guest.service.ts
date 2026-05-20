import api from './api.service'
import type { Guest, Reservation, ApiSuccess } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Service Clients
// ──────────────────────────────────────────────────────────────

export const guestService = {
  /**
   * GET /api/guests?page= — liste paginée
   */
  async list(page = 1): Promise<Guest[]> {
    const { data } = await api.get<ApiSuccess<Guest[]>>('/guests', { params: { page } })
    return data.data
  },

  /**
   * GET /api/guests?q= — recherche
   */
  async search(q: string): Promise<Guest[]> {
    const { data } = await api.get<ApiSuccess<Guest[]>>('/guests', { params: { q } })
    return data.data
  },

  /**
   * GET /api/guests/{id}
   */
  async getOne(id: string): Promise<Guest> {
    const { data } = await api.get<ApiSuccess<Guest>>(`/guests/${id}`)
    return data.data
  },

  /**
   * POST /api/guests
   */
  async create(payload: Record<string, unknown>): Promise<Guest> {
    const { data } = await api.post<ApiSuccess<Guest>>('/guests', payload)
    return data.data
  },

  /**
   * PUT /api/guests/{id}
   */
  async update(id: string, payload: Record<string, unknown>): Promise<Guest> {
    const { data } = await api.put<ApiSuccess<Guest>>(`/guests/${id}`, payload)
    return data.data
  },

  /**
   * GET /api/guests/{id}/stays
   */
  async stays(id: string): Promise<Reservation[]> {
    const { data } = await api.get<ApiSuccess<Reservation[]>>(`/guests/${id}/stays`)
    return data.data
  },
}
