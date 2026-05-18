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
  let   unsubscribe: (() => void) | null = null

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
    } catch (e: unknown) {
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
   * Démarre l'abonnement Mercure pour les mises à jour de statut en temps réel.
   * Doit être appelé après le chargement initial des chambres.
   */
  function subscribeToMercure(): void {
    const authStore = useAuthStore()
    const tenantId  = authStore.tenantId

    if (!tenantId) return

    const topic = mercureService.buildTopic(tenantId, 'room.status.changed')

    unsubscribe = mercureService.subscribe<RoomStatusChangedEvent>(
      topic,
      (event) => {
        const room = rooms.value.find(r => r.id === event.roomId)
        if (room) {
          room.status = event.status
        }
      },
    )
  }

  /**
   * Arrête l'abonnement Mercure (à appeler lors du démontage du composant).
   */
  function unsubscribeFromMercure(): void {
    if (unsubscribe) {
      unsubscribe()
      unsubscribe = null
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
    subscribeToMercure,
    unsubscribeFromMercure,
  }
})
