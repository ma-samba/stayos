import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { reservationService } from '@/services/reservation.service'
import { mercureService } from '@/services/mercure.service'
import { useAuthStore } from '@/stores/auth.store'
import type {
  Reservation,
  ReservationStatus,
  CreateReservationPayload,
  UpdateReservationPayload,
} from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Store Réservations
// ──────────────────────────────────────────────────────────────

interface ReservationLifecycleEvent {
  id: string
  confirmationNumber?: string
  room?: string
  reason?: string
  guest?: string
  checkIn?: string
  checkOut?: string
}

const CREATED_REFETCH_DELAY_MS = 1500

export const useReservationsStore = defineStore('reservations', () => {
  const reservations = ref<Reservation[]>([])
  const loading      = ref(false)
  const error        = ref<string | null>(null)

  // ── Refcount Mercure ──────────────────────────────────────
  let liveRefCount = 0
  const unsubscribers: Array<() => void> = []
  let lastFetchParams: Parameters<typeof fetchReservations>[0]
  let refetchTimer: ReturnType<typeof setTimeout> | null = null

  // ── Compteurs par statut ──

  const confirmedCount  = computed(() => reservations.value.filter(r => r.status === 'confirmed').length)
  const checkedInCount  = computed(() => reservations.value.filter(r => r.status === 'checked_in').length)
  const checkedOutCount = computed(() => reservations.value.filter(r => r.status === 'checked_out').length)
  const cancelledCount  = computed(() => reservations.value.filter(r => r.status === 'cancelled').length)

  // ── Actions ──

  async function fetchReservations(params?: { status?: string; from?: string; to?: string }): Promise<void> {
    loading.value = true
    error.value   = null
    lastFetchParams = params

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

  // ── Mercure : patch local ────────────────────────────────
  // Le payload des events checkin/checkout/cancelled est trop mince
  // pour reconstruire une Reservation complète (pas de guest, pas de
  // dates) — on se contente de patcher le status. Le payload created
  // est encore plus mince → refetch debouncé.

  function patchStatus(id: string, status: ReservationStatus): void {
    const r = reservations.value.find(x => x.id === id)
    if (!r) return
    r.status = status
    const nowIso = new Date().toISOString()
    if (status === 'checked_in'  && !r.checkedInAt)  r.checkedInAt  = nowIso
    if (status === 'checked_out' && !r.checkedOutAt) r.checkedOutAt = nowIso
  }

  function scheduleCreatedRefetch(): void {
    if (refetchTimer) clearTimeout(refetchTimer)
    refetchTimer = setTimeout(() => {
      refetchTimer = null
      void fetchReservations(lastFetchParams)
    }, CREATED_REFETCH_DELAY_MS)
  }

  function subscribeLive(): void {
    liveRefCount++
    if (unsubscribers.length > 0) return

    const auth = useAuthStore()
    const tenantId = auth.tenantId
    if (!tenantId) return

    // Une seule EventSource multiplexée pour les 4 topics. Les payloads
    // de checkin et checkout étant identiques côté backend, on infère
    // lequel des deux a fired en lisant le statut actuel de la résa
    // dans le store local (machine à états : confirmed/pending → checkin,
    // checked_in → checkout).
    const topics = [
      mercureService.buildTopic(tenantId, 'reservation.created'),
      mercureService.buildTopic(tenantId, 'reservation.checkin'),
      mercureService.buildTopic(tenantId, 'reservation.checkout'),
      mercureService.buildTopic(tenantId, 'reservation.cancelled'),
    ]

    unsubscribers.push(
      mercureService.subscribeMany<ReservationLifecycleEvent>(topics, (data) => {
        if (!data || !data.id) return

        // cancelled : payload unique avec `reason`
        if (data.reason !== undefined) {
          patchStatus(data.id, 'cancelled')
          return
        }

        // created : payload unique avec `guest`/`checkIn`/`checkOut`
        if (data.guest !== undefined || data.checkIn !== undefined) {
          scheduleCreatedRefetch()
          return
        }

        // checkin/checkout : ambigus → inférence depuis le statut local
        const current = reservations.value.find(r => r.id === data.id)
        if (!current) return
        if (current.status === 'confirmed' || current.status === 'pending') {
          patchStatus(data.id, 'checked_in')
        } else if (current.status === 'checked_in') {
          patchStatus(data.id, 'checked_out')
        }
        // Sinon (déjà checked_out/cancelled) : on ignore, doublon possible
      }),
    )
  }

  function unsubscribeLive(): void {
    if (liveRefCount > 0) liveRefCount--
    if (liveRefCount === 0) {
      while (unsubscribers.length > 0) {
        const fn = unsubscribers.pop()
        try { fn?.() } catch { /* noop */ }
      }
      if (refetchTimer) {
        clearTimeout(refetchTimer)
        refetchTimer = null
      }
    }
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
    subscribeLive,
    unsubscribeLive,
  }
})
