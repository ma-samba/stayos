// ──────────────────────────────────────────────────────────────
//  Entités métier — types TypeScript alignés avec les entités PHP
// ──────────────────────────────────────────────────────────────

export type RoomStatus = 'available' | 'occupied' | 'cleaning' | 'maintenance' | 'out_of_order'

export interface Floor {
  id: string
  number: number
  name: string | null
}

export interface RoomType {
  id: string
  name: string
  baseRateXof: string
  maxOccupancy: number
}

export interface Room {
  id: string
  number: string
  status: RoomStatus
  notes: string | null
  isActive: boolean
  floor: Floor | null
  type: RoomType
}

// ──────────────────────────────────────────────────────────────
//  Réponses API standard
// ──────────────────────────────────────────────────────────────

export interface ApiSuccess<T> {
  data: T
  status: number
  message: string
}

export interface ApiError {
  error: string
  code: string
  status: number
}

// ──────────────────────────────────────────────────────────────
//  Mercure SSE — événements temps réel
// ──────────────────────────────────────────────────────────────

export interface RoomStatusChangedEvent {
  roomId: string
  roomNumber: string
  status: RoomStatus
}
