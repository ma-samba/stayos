import api from './api.service'
import type { Room, RoomType, RoomStatus, RoomUsage, Floor, ApiSuccess } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Service Chambres + Étages + Types
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
   * GET /api/rooms/usage
   */
  async getUsage(): Promise<RoomUsage> {
    const { data } = await api.get<ApiSuccess<RoomUsage>>('/rooms/usage')
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
   * POST /api/rooms
   */
  async create(payload: {
    number: string
    typeId: string
    floorId?: string | null
    notes?: string | null
    isActive?: boolean
  }): Promise<Room> {
    const { data } = await api.post<ApiSuccess<Room>>('/rooms', payload)
    return data.data
  },

  /**
   * POST /api/rooms/bulk
   */
  async bulkCreate(payload: {
    floorId: string
    typeId: string
    startNumber: number
    count: number
    prefix?: string | null
  }): Promise<Room[]> {
    const { data } = await api.post<ApiSuccess<Room[]>>('/rooms/bulk', payload)
    return data.data
  },

  /**
   * PUT /api/rooms/{id}
   */
  async update(id: string, payload: Record<string, unknown>): Promise<Room> {
    const { data } = await api.put<ApiSuccess<Room>>(`/rooms/${id}`, payload)
    return data.data
  },

  /**
   * DELETE /api/rooms/{id} — Soft delete (204).
   */
  async softDelete(id: string): Promise<void> {
    await api.delete(`/rooms/${id}`)
  },

  /**
   * POST /api/rooms/{id}/reactivate
   */
  async reactivate(id: string): Promise<Room> {
    const { data } = await api.post<ApiSuccess<Room>>(`/rooms/${id}/reactivate`)
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

// ──────────────────────────────────────────────────────────────
//  Types de chambre — Sprint 13ter
// ──────────────────────────────────────────────────────────────

export const roomTypeService = {
  /**
   * GET /api/room-types
   */
  async getAll(): Promise<RoomType[]> {
    const { data } = await api.get<ApiSuccess<RoomType[]>>('/room-types')
    return data.data
  },

  /**
   * POST /api/room-types
   */
  async create(payload: {
    name: string
    description?: string | null
    baseRateXof: string
    maxOccupancy: number
    bedConfiguration?: Array<{ type: string; count: number }> | null
    amenities?: string[] | null
    sortOrder?: number | null
  }): Promise<RoomType> {
    const { data } = await api.post<ApiSuccess<RoomType>>('/room-types', payload)
    return data.data
  },

  /**
   * PUT /api/room-types/{id}
   */
  async update(id: string, payload: Record<string, unknown>): Promise<RoomType> {
    const { data } = await api.put<ApiSuccess<RoomType>>(`/room-types/${id}`, payload)
    return data.data
  },

  /**
   * DELETE /api/room-types/{id}
   */
  async delete(id: string): Promise<void> {
    await api.delete(`/room-types/${id}`)
  },
}

// ──────────────────────────────────────────────────────────────
//  Étages — Sprint 13ter
// ──────────────────────────────────────────────────────────────

export const floorService = {
  /**
   * GET /api/floors
   */
  async getAll(): Promise<Floor[]> {
    const { data } = await api.get<ApiSuccess<Floor[]>>('/floors')
    return data.data
  },

  /**
   * POST /api/floors
   */
  async create(payload: { number: number; name?: string | null }): Promise<Floor> {
    const { data } = await api.post<ApiSuccess<Floor>>('/floors', payload)
    return data.data
  },

  /**
   * PUT /api/floors/{id}
   */
  async update(id: string, payload: { number?: number; name?: string | null }): Promise<Floor> {
    const { data } = await api.put<ApiSuccess<Floor>>(`/floors/${id}`, payload)
    return data.data
  },

  /**
   * DELETE /api/floors/{id}
   */
  async delete(id: string): Promise<void> {
    await api.delete(`/floors/${id}`)
  },

  async deactivate(id: string): Promise<Floor> {
    const { data } = await api.post<ApiSuccess<Floor>>(`/floors/${id}/deactivate`)
    return data.data
  },

  async reactivate(id: string): Promise<Floor> {
    const { data } = await api.post<ApiSuccess<Floor>>(`/floors/${id}/reactivate`)
    return data.data
  },
}
