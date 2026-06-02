import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { roomService } from '@/services/room.service'
import { mercureService } from '@/services/mercure.service'
import { useAuthStore } from '@/stores/auth.store'
import type { Room, RoomStatus, RoomStatusChangedEvent } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Store Chambres
// ──────────────────────────────────────────────────────────────

export const useRoomsStore = defineStore('rooms', () => {
  const rooms       = ref<Room[]>([])
  const loading     = ref(false)
  const error       = ref<string | null>(null)

  // ── Refcount Mercure ──────────────────────────────────────
  // Plusieurs vues peuvent partager le store. On ouvre l'EventSource
  // au premier subscribeLive() et on le ferme au dernier unsubscribeLive().
  let liveRefCount = 0
  let unsubStatus: (() => void) | null = null

  // ── Computed ──────────────────────────────────────────────

  const roomsByFloor = computed(() => {
    const grouped: Record<string, Room[]> = {}
    for (const room of rooms.value) {
      const floorKey = room.floor
        ? `Étage ${room.floor.number}${room.floor.name ? ` — ${room.floor.name}` : ''}`
        : 'Sans étage'
      if (!grouped[floorKey]) grouped[floorKey] = []
      grouped[floorKey].push(room)
    }
    return grouped
  })

  const availableCount   = computed(() => rooms.value.filter(r => r.status === 'available').length)
  const occupiedCount    = computed(() => rooms.value.filter(r => r.status === 'occupied').length)
  const cleaningCount    = computed(() => rooms.value.filter(r => r.status === 'cleaning').length)
  const maintenanceCount = computed(() => rooms.value.filter(r => r.status === 'maintenance' || r.status === 'out_of_order').length)

  const occupancyRate = computed(() => {
    if (rooms.value.length === 0) return 0
    return Math.round((occupiedCount.value / rooms.value.length) * 100)
  })

  // ── Actions ───────────────────────────────────────────────

  async function fetchRooms(): Promise<void> {
    loading.value = true
    error.value   = null

    try {
      rooms.value = await roomService.getAll()
    } catch {
      error.value = 'Impossible de charger les chambres'
    } finally {
      loading.value = false
    }
  }

  async function updateRoomStatus(
    roomId: string,
    status: RoomStatus,
    notes?: string,
  ): Promise<void> {
    const updated = await roomService.updateStatus(roomId, status, notes)
    const index   = rooms.value.findIndex(r => r.id === roomId)
    if (index !== -1) {
      rooms.value[index] = updated
    }
  }

  /**
   * Patch local du statut d'une chambre. Appelé par le handler Mercure
   * sans refetch — la chambre doit déjà être dans `rooms` (sinon
   * l'event ne concerne pas la liste courante).
   */
  function patchRoomStatusLocal(roomId: string, status: RoomStatus): void {
    const room = rooms.value.find(r => r.id === roomId)
    if (room) room.status = status
  }

  /**
   * Démarre l'écoute live (idempotent via refcount).
   */
  function subscribeLive(): void {
    liveRefCount++
    if (unsubStatus) return

    const auth = useAuthStore()
    const tenantId = auth.tenantId
    if (!tenantId) return

    const topic = mercureService.buildTopic(tenantId, 'room.status.changed')
    unsubStatus = mercureService.subscribe<RoomStatusChangedEvent>(topic, (event) => {
      patchRoomStatusLocal(event.roomId, event.status)
    })
  }

  /**
   * Arrête l'écoute live (décrément refcount, fermeture au 0).
   */
  function unsubscribeLive(): void {
    if (liveRefCount > 0) liveRefCount--
    if (liveRefCount === 0 && unsubStatus) {
      unsubStatus()
      unsubStatus = null
    }
  }

  return {
    rooms,
    loading,
    error,
    roomsByFloor,
    availableCount,
    occupiedCount,
    cleaningCount,
    maintenanceCount,
    occupancyRate,
    fetchRooms,
    updateRoomStatus,
    subscribeLive,
    unsubscribeLive,
  }
})
