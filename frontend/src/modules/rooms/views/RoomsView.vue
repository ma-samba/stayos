<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useRoomsStore } from '@/stores/rooms.store'
import { useAuthStore } from '@/stores/auth.store'
import RoomCard from '@/modules/rooms/components/RoomCard.vue'
import type { RoomStatus } from '@/types/entities'

const router = useRouter()
const auth = useAuthStore()

// ──────────────────────────────────────────────────────────────
//  Store
// ──────────────────────────────────────────────────────────────

const store = useRoomsStore()

// ──────────────────────────────────────────────────────────────
//  Filtre par statut
// ──────────────────────────────────────────────────────────────

const activeFilter = ref<RoomStatus | 'all'>('all')

const statusFilters: { value: RoomStatus | 'all'; label: string }[] = [
  { value: 'all',          label: 'Toutes' },
  { value: 'available',    label: 'Disponibles' },
  { value: 'occupied',     label: 'Occupées' },
  { value: 'cleaning',     label: 'Ménage' },
  { value: 'maintenance',  label: 'Maintenance' },
  { value: 'out_of_order', label: 'Hors service' },
]

const filteredRoomsByFloor = computed(() => {
  if (activeFilter.value === 'all') return store.roomsByFloor

  const result: Record<string, typeof store.rooms> = {}
  for (const [floor, rooms] of Object.entries(store.roomsByFloor)) {
    const filtered = rooms.filter(r => r.status === activeFilter.value)
    if (filtered.length > 0) result[floor] = filtered
  }
  return result
})

// ──────────────────────────────────────────────────────────────
//  Modal de confirmation changement statut
// ──────────────────────────────────────────────────────────────

const pendingChange = ref<{ roomId: string; status: RoomStatus } | null>(null)
const changeNotes   = ref('')
const changing      = ref(false)

function requestStatusChange(roomId: string, status: RoomStatus): void {
  pendingChange.value = { roomId, status }
  changeNotes.value   = ''
}

async function confirmStatusChange(): Promise<void> {
  if (!pendingChange.value) return
  changing.value = true

  try {
    await store.updateRoomStatus(
      pendingChange.value.roomId,
      pendingChange.value.status,
      changeNotes.value || undefined,
    )
    pendingChange.value = null
  } finally {
    changing.value = false
  }
}

// ──────────────────────────────────────────────────────────────
//  Cycle de vie
// ──────────────────────────────────────────────────────────────

onMounted(async () => {
  await store.fetchRooms()
  store.subscribeLive()
})

onUnmounted(() => {
  store.unsubscribeLive()
})

// ──────────────────────────────────────────────────────────────
//  Libellés statut
// ──────────────────────────────────────────────────────────────

const statusLabel: Record<RoomStatus, string> = {
  available:    'disponible',
  occupied:     'occupée',
  cleaning:     'en ménage',
  maintenance:  'en maintenance',
  out_of_order: 'hors service',
}
</script>

<template>
  <div style="padding:1.5rem; max-width:1400px; margin:0 auto;">
<!-- ── En-tête ── -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
      <div>
        <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
          Plan des chambres
        </h1>
        <p class="t-muted">Statut en temps réel · Mis à jour automatiquement</p>
      </div>
      <button
        v-if="auth.canAccess('configuration')"
        class="btn btn-secondary"
        @click="router.push('/configuration')"
      >
        <i class="ti ti-settings" aria-hidden="true"></i>
        Configurer l'hôtel
      </button>
    </div>

    <!-- ── Stat cards ── -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:1.5rem;">
      <div class="stat-card">
        <div class="stat-label">Disponibles</div>
        <div class="stat-value" style="color:var(--pms-green);">{{ store.availableCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Occupées</div>
        <div class="stat-value" style="color:var(--pms-red);">{{ store.occupiedCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Ménage</div>
        <div class="stat-value" style="color:var(--pms-gold);">{{ store.cleaningCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Maintenance</div>
        <div class="stat-value" style="color:var(--pms-blue);">{{ store.maintenanceCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Occupation physique</div>
        <div class="stat-value">{{ store.occupancyRate }}%</div>
        <div class="t-muted" style="font-size:11px; margin-top:4px;">d'après statut des chambres</div>
      </div>
    </div>

    <!-- ── Filtres par statut ── -->
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.5rem;">
      <button
        v-for="f in statusFilters"
        :key="f.value"
        :class="['btn', 'btn-sm', activeFilter === f.value ? 'btn-primary' : 'btn-secondary']"
        @click="activeFilter = f.value"
      >
        {{ f.label }}
      </button>
    </div>

    <!-- ── État chargement ── -->
    <div v-if="store.loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>

    <!-- ── Erreur ── -->
    <div v-else-if="store.error" class="empty-state">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <div>{{ store.error }}</div>
      <button class="btn btn-secondary btn-sm" @click="store.fetchRooms()">Réessayer</button>
    </div>

    <!-- ── Aucun résultat avec filtre ── -->
    <div v-else-if="Object.keys(filteredRoomsByFloor).length === 0" class="empty-state">
      <i class="ti ti-bed-off" aria-hidden="true"></i>
      <div>Aucune chambre dans ce statut</div>
    </div>

    <!-- ── Plan d'étage ── -->
    <div v-else>
      <div
        v-for="(rooms, floorLabel) in filteredRoomsByFloor"
        :key="floorLabel"
        style="margin-bottom:2rem;"
      >
        <!-- Étiquette étage -->
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
          <span class="t-label">{{ floorLabel }}</span>
          <div style="flex:1; height:0.5px; background:var(--pms-border);"></div>
          <span class="t-muted">{{ rooms.length }} chambre{{ rooms.length > 1 ? 's' : '' }}</span>
        </div>

        <!-- Grille de chambres -->
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:12px;">
          <RoomCard
            v-for="room in rooms"
            :key="room.id"
            :room="room"
            @status-change="requestStatusChange"
          />
        </div>
      </div>
    </div>

    <!-- ── Modal confirmation changement statut ── -->
    <div
      v-if="pendingChange"
      style="position:fixed; inset:0; background:rgba(26,23,20,0.4); display:flex; align-items:center; justify-content:center; z-index:100;"
      @click.self="pendingChange = null"
    >
      <div style="background:#fff; border-radius:var(--radius-xl); padding:1.5rem; width:380px; max-width:90vw;">
        <div style="margin-bottom:1rem;">
          <h3 style="font-size:16px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
            Changer le statut
          </h3>
          <p class="t-muted">
            Marquer la chambre comme
            <strong>{{ statusLabel[pendingChange.status] }}</strong>
          </p>
        </div>

        <div class="input-wrap" style="margin-bottom:1rem;">
          <label class="input-label">Note (optionnel)</label>
          <input
            v-model="changeNotes"
            class="input"
            placeholder="Ex: Fuite robinet, client VIP..."
          />
        </div>

        <div style="display:flex; gap:8px; justify-content:flex-end;">
          <button class="btn btn-ghost btn-sm" @click="pendingChange = null">
            Annuler
          </button>
          <button
            class="btn btn-primary btn-sm"
            :disabled="changing"
            @click="confirmStatusChange()"
          >
            <span v-if="changing" class="spinner" style="width:14px; height:14px; border-width:1.5px;"></span>
            <span v-else>Confirmer</span>
          </button>
        </div>
      </div>
    </div>
</div>
</template>
