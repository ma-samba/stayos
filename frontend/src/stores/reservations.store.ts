import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { reservationService } from '@/services/reservation.service'
import type {
  Reservation,
  ReservationStatus,
  CreateReservationPayload,
  UpdateReservationPayload,
} from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Store Réservations
// ──────────────────────────────────────────────────────────────

export const useReservationsStore = defineStore('reservations', () => {
  const reservations = ref<Reservation[]>([])
  const loading      = ref(false)
  const error        = ref<string | null>(null)

  // ── Compteurs par statut ──

  const confirmedCount  = computed(() => reservations.value.filter(r => r.status === 'confirmed').length)
  const checkedInCount  = computed(() => reservations.value.filter(r => r.status === 'checked_in').length)
  const checkedOutCount = computed(() => reservations.value.filter(r => r.status === 'checked_out').length)
  const cancelledCount  = computed(() => reservations.value.filter(r => r.status === 'cancelled').length)

  // ── Actions ──

  async function fetchReservations(params?: { status?: string; from?: string; to?: string }): Promise<void> {
    loading.value = true
    error.value   = null

    try {
      reservations.value = await reservationService.getAll(params)
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Erreur lors du chargement des réservations'
    } finally {
      loading.value = false
    }
  }

  async function createReservation(payload: CreateReservationPayload): Promise<Reservation> {
    const reservation = await reservationService.create(payload)
    reservations.value.unshift(reservation)
    return reservation
  }

  async function updateReservation(id: string, payload: UpdateReservationPayload): Promise<Reservation> {
    const updated = await reservationService.update(id, payload)
    const index = reservations.value.findIndex(r => r.id === id)
    if (index !== -1) reservations.value[index] = updated
    return updated
  }

  async function checkIn(id: string, notes?: string): Promise<Reservation> {
    const updated = await reservationService.checkIn(id, notes)
    const index = reservations.value.findIndex(r => r.id === id)
    if (index !== -1) reservations.value[index] = updated
    return updated
  }

  async function checkOut(id: string): Promise<Reservation> {
    const updated = await reservationService.checkOut(id)
    const index = reservations.value.findIndex(r => r.id === id)
    if (index !== -1) reservations.value[index] = updated
    return updated
  }

  async function cancel(id: string, reason: string): Promise<Reservation> {
    const updated = await reservationService.cancel(id, reason)
    const index = reservations.value.findIndex(r => r.id === id)
    if (index !== -1) reservations.value[index] = updated
    return updated
  }

  function filterByStatus(status: ReservationStatus | 'all'): Reservation[] {
    if (status === 'all') return reservations.value
    return reservations.value.filter(r => r.status === status)
  }

  return {
    reservations,
    loading,
    error,
    confirmedCount,
    checkedInCount,
    checkedOutCount,
    cancelledCount,
    fetchReservations,
    createReservation,
    updateReservation,
    checkIn,
    checkOut,
    cancel,
    filterByStatus,
  }
})
