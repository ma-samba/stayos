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
//  Réservations
// ──────────────────────────────────────────────────────────────

export type ReservationStatus = 'confirmed' | 'pending' | 'checked_in' | 'checked_out' | 'cancelled' | 'no_show'
export type ReservationSource = 'direct' | 'booking_com' | 'airbnb' | 'expedia' | 'walk_in'

export interface Guest {
  id: string
  firstName: string
  lastName: string
  email: string | null
  phone: string | null
  nationality?: string | null
  documentType?: string | null
  documentNumber?: string | null
  documentUrl?: string | null
  address?: string | null
  city?: string | null
  country?: string | null
  totalStays?: number
}

export interface Reservation {
  id: string
  confirmationNumber: string
  status: ReservationStatus
  checkIn: string
  checkOut: string
  adults: number
  children: number
  rateXof: string
  totalXof: string
  source: string
  notes: string | null
  specialRequests: string | null
  checkedInAt: string | null
  checkedOutAt: string | null
  guest: Guest
  room: Room
}

export interface ReservationGantt {
  id: string
  confirmationNumber: string
  status: ReservationStatus
  checkIn: string
  checkOut: string
  room: {
    id: string
    number: string
    type: { id: string; name: string }
  }
}

export interface CreateReservationPayload {
  roomId: string
  guestId: string
  checkIn: string
  checkOut: string
  adults: number
  children: number
  notes?: string
  specialRequests?: string
  source?: string
  depositXof?: string
}

export interface UpdateReservationPayload {
  roomId?: string
  guestId?: string
  checkIn?: string
  checkOut?: string
  adults?: number
  children?: number
  notes?: string
  specialRequests?: string
  source?: string
  depositXof?: string
}

// ──────────────────────────────────────────────────────────────
//  Facturation
// ──────────────────────────────────────────────────────────────

export type InvoiceStatus = 'draft' | 'issued' | 'partial' | 'paid' | 'cancelled'
export type PaymentStatus = 'init' | 'pending' | 'paid' | 'failed' | 'cancelled'

export interface Payment {
  id: string
  method: string
  amountXof: string
  status: string
  reference: string | null
  gatewayName: string | null
  paidAt: string | null
  processedAt: string | null
}

export interface InvoiceLine {
  id: string
  label: string
  quantity: number
  unitPriceXof: string
  totalXof: string
}

export interface Invoice {
  id: string
  number: string
  status: InvoiceStatus
  subtotalXof: string
  taxRate: string
  taxXof: string
  totalXof: string
  paidXof: string
  balanceXof: string
  issuedAt: string | null
  dueAt: string | null
  notes: string | null
  reservation?: {
    id: string
    confirmationNumber: string
    guest?: Guest
    room?: { id: string; number: string; type?: { name: string } }
  }
  lines?: InvoiceLine[]
  payments?: Payment[]
  createdAt: string
}

// ──────────────────────────────────────────────────────────────
//  Housekeeping
// ──────────────────────────────────────────────────────────────

export type CleaningStatus = 'pending' | 'in_progress' | 'done' | 'inspected' | 'skipped'
export type CleaningType   = 'departure' | 'stay_over' | 'inspection' | 'maintenance'

export interface CleaningTask {
  id: string
  status: CleaningStatus
  type: CleaningType
  scheduledAt: string
  startedAt: string | null
  completedAt: string | null
  notes: string | null
  roomNumber: string
  roomType: string | null
  roomId: string
  assignedToName: string | null
  assignedToId: string | null
}

export interface TaskAssignedEvent {
  taskId: string
  roomNumber: string
  type: string
  assignedToId: string
  assignedToName: string
  scheduledAt: string
}

export interface TaskUpdatedEvent {
  taskId: string
  roomNumber: string
  status: CleaningStatus
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
