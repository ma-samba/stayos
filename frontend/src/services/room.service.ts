import api from './api.service'
import type { Room, RoomStatus, ApiSuccess } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Service Chambres
// ──────────────────────────────────────────────────────────────

export const roomService = {
  /**
   * GET /api/rooms — toutes les chambres actives
   */
  async getAll(): Promise<Room[]> {
    const { data } = await api.get<ApiSuccess<Room[]>>('/rooms')
    return data.data
  },

  /**
   * GET /api/rooms/available?from=&to=&adults=
   */
  async getAvailable(from: string, to: string, adults: number): Promise<Room[]> {
    const { data } = await api.get<ApiSuccess<Room[]>>('/rooms/available', {
      params: { from, to, adults },
    })
    return data.data
  },

  /**
   * GET /api/rooms/{id}
   */
  async getOne(id: string): Promise<Room> {
    const { data } = await api.get<ApiSuccess<Room>>(`/rooms/${id}`)
    return data.data
  },

  /**
   * PATCH /api/rooms/{id}/status
   */
  async updateStatus(id: string, status: RoomStatus, notes?: string): Promise<Room> {
    const { data } = await api.patch<ApiSuccess<Room>>(`/rooms/${id}/status`, {
      status,
      notes: notes ?? null,
    })
    return data.data
  },
}
