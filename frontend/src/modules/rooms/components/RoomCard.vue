<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import type { Room, RoomStatus } from '@/types/entities'
import { formatCurrency } from '@/utils/currency'

const router = useRouter()

// ──────────────────────────────────────────────────────────────
//  Props & Emits
// ──────────────────────────────────────────────────────────────

const props = defineProps<{
  room: Room
}>()

const emit = defineEmits<{
  statusChange: [roomId: string, status: RoomStatus]
}>()

// ──────────────────────────────────────────────────────────────
//  Computed
// ──────────────────────────────────────────────────────────────

const statusLabel: Record<RoomStatus, string> = {
  available:   'Disponible',
  occupied:    'Occupée',
  cleaning:    'Ménage',
  maintenance: 'Maintenance',
  out_of_order: 'Hors service',
}

const label = computed(() => statusLabel[props.room.status])

const nextStatuses = computed((): RoomStatus[] => {
  switch (props.room.status) {
    case 'available':    return ['maintenance', 'out_of_order']
    case 'occupied':     return ['cleaning']
    case 'cleaning':     return ['available', 'maintenance']
    case 'maintenance':  return ['available', 'out_of_order']
    case 'out_of_order': return ['maintenance', 'available']
    default:             return []
  }
})

const nextStatusLabels: Record<RoomStatus, string> = {
  available:    'Marquer disponible',
  occupied:     'Marquer occupée',
  cleaning:     'Mettre en ménage',
  maintenance:  'Mettre en maintenance',
  out_of_order: 'Mettre hors service',
}
</script>

<template>
  <div :class="['room-card', room.status]" style="cursor:pointer;" @click="router.push(`/rooms/${room.id}`)">
    <!-- Numéro + type -->
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
      <div>
        <div style="font-size:18px; font-weight:500; color:var(--pms-ink); font-family:var(--mono);">
          {{ room.number }}
        </div>
        <div style="font-size:12px; color:var(--pms-ink-3); margin-top:2px;">
          {{ room.type.name }}
        </div>
      </div>
      <span :class="['badge', `badge-${room.status}`]">
        <span class="badge-dot"></span>
        {{ label }}
      </span>
    </div>

    <!-- Infos complémentaires -->
    <div style="font-size:12px; color:var(--pms-ink-3); display:flex; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
      <span v-if="room.floor">
        <i class="ti ti-stairs" aria-hidden="true"></i>
        Étage {{ room.floor.number }}
      </span>
      <span>
        <i class="ti ti-users" aria-hidden="true"></i>
        {{ room.type.maxOccupancy }} pers.
      </span>
      <span class="t-mono" style="color:var(--pms-teal);">
        {{ formatCurrency(room.type.baseRateXof, { perNight: true }) }}
      </span>
    </div>

    <!-- Note si présente -->
    <div v-if="room.notes" style="font-size:11px; color:var(--pms-ink-3); font-style:italic; margin-bottom:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
      {{ room.notes }}
    </div>

    <!-- Actions rapides de changement de statut -->
    <div v-if="nextStatuses.length > 0" style="display:flex; gap:6px; flex-wrap:wrap; margin-top:4px;">
      <button
        v-for="nextStatus in nextStatuses"
        :key="nextStatus"
        class="btn btn-ghost btn-sm"
        style="font-size:11px; height:26px; padding:0 8px; color:var(--pms-ink-3); border:0.5px solid var(--pms-border-2);"
        @click.stop="emit('statusChange', room.id, nextStatus)"
      >
        {{ nextStatusLabels[nextStatus] }}
      </button>
    </div>
  </div>
</template>
