<script setup lang="ts">
import { ref } from 'vue'
import type { CleaningTask, CleaningStatus } from '@/types/entities'
import { housekeepingService } from '@/services/housekeeping.service'

const props = defineProps<{
  task: CleaningTask
  isSupervisor: boolean
}>()

const emit = defineEmits<{
  refresh: []
}>()

const busy = ref(false)
const errorMsg = ref<string | null>(null)
const confirmingSkip = ref(false)

// ── Labels ──

const typeLabels: Record<string, string> = {
  departure:   'Départ',
  stay_over:   'Recouche',
  inspection:  'Inspection',
  maintenance: 'Maintenance',
}

const statusLabels: Record<CleaningStatus, string> = {
  pending:     'À faire',
  in_progress: 'En cours',
  done:        'Terminé',
  inspected:   'Inspecté',
  skipped:     'Ignoré',
}

// ── Helpers ──

function formatTime(dateStr: string): string {
  const d = new Date(dateStr)
  if (isNaN(d.getTime())) return '--:--'
  return d.toLocaleTimeString('fr-SN', { hour: '2-digit', minute: '2-digit' })
}

// ── Actions ──

async function changeStatus(newStatus: CleaningStatus): Promise<void> {
  busy.value = true
  errorMsg.value = null
  try {
    await housekeepingService.updateStatus(props.task.id, newStatus)
    emit('refresh')
  } catch (err: unknown) {
    const resp = (err as { response?: { data?: { error?: string }; status?: number } }).response
    if (resp?.status === 403) {
      errorMsg.value = 'Vous ne pouvez modifier que vos propres tâches.'
    } else if (resp?.status === 422) {
      errorMsg.value = resp.data?.error ?? 'Action impossible.'
    } else {
      errorMsg.value = 'Erreur inattendue.'

    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="task-card" :class="'task-' + task.status">
    <!-- Top row: room + type badge -->
    <div class="task-header">
      <div class="task-room">{{ task.roomNumber }}</div>
      <span class="badge-type" :class="'type-' + task.type">
        {{ typeLabels[task.type] ?? task.type }}
      </span>
    </div>

    <!-- Room type name -->
    <div v-if="task.roomType" class="task-room-type">{{ task.roomType }}</div>

    <!-- Status badge -->
    <div style="margin:8px 0 6px;">
      <span class="badge-status" :class="'status-' + task.status">
        <span class="badge-dot"></span>{{ statusLabels[task.status] }}
      </span>
    </div>

    <!-- Assigned + time -->
    <div class="task-meta">
      <span v-if="task.assignedToName" class="task-assigned">
        <i class="ti ti-user" aria-hidden="true"></i> {{ task.assignedToName }}
      </span>
      <span v-else class="task-unassigned">Non assigné</span>
      <span class="task-time">
        <i class="ti ti-clock" aria-hidden="true"></i> {{ formatTime(task.scheduledAt) }}
      </span>
    </div>

    <!-- Notes -->
    <div v-if="task.notes" class="task-notes">{{ task.notes }}</div>

    <!-- Error message -->
    <div v-if="errorMsg" class="task-error">
      <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ errorMsg }}
    </div>

    <!-- Action buttons -->
    <div class="task-actions">
      <!-- pending → Commencer -->
      <button
        v-if="task.status === 'pending'"
        class="btn btn-primary btn-action"
        :disabled="busy"
        @click="changeStatus('in_progress')"
      >
        <i class="ti ti-player-play" aria-hidden="true"></i> Commencer
      </button>

      <!-- in_progress → Terminer -->
      <button
        v-if="task.status === 'in_progress'"
        class="btn btn-primary btn-action"
        :disabled="busy"
        @click="changeStatus('done')"
      >
        <i class="ti ti-check" aria-hidden="true"></i> Terminer
      </button>

      <!-- done → Inspecter (supervisor ideally) -->
      <button
        v-if="task.status === 'done'"
        class="btn btn-primary btn-action"
        :disabled="busy"
        @click="changeStatus('inspected')"
      >
        <i class="ti ti-eye-check" aria-hidden="true"></i> Inspecter
      </button>

      <!-- inspected → terminal -->
      <div v-if="task.status === 'inspected'" class="task-done-badge">
        <i class="ti ti-circle-check" aria-hidden="true"></i> Inspecté
      </div>

      <!-- skipped → Réactiver -->
      <button
        v-if="task.status === 'skipped'"
        class="btn btn-secondary btn-action"
        :disabled="busy"
        @click="changeStatus('pending')"
      >
        <i class="ti ti-rotate" aria-hidden="true"></i> Réactiver
      </button>

      <!-- Skip (secondary) on pending/in_progress -->
      <button
        v-if="(task.status === 'pending' || task.status === 'in_progress') && !confirmingSkip"
        class="btn btn-ghost btn-action-sm"
        :disabled="busy"
        @click="confirmingSkip = true"
      >
        Ignorer
      </button>
    </div>

    <!-- Inline skip confirmation -->
    <div v-if="confirmingSkip" class="skip-confirm">
      <span class="skip-confirm-text">Ignorer cette tâche ?</span>
      <div class="skip-confirm-actions">
        <button
          class="btn btn-danger btn-sm"
          :disabled="busy"
          @click="changeStatus('skipped'); confirmingSkip = false"
        >
          Confirmer
        </button>
        <button
          class="btn btn-ghost btn-sm"
          :disabled="busy"
          @click="confirmingSkip = false"
        >
          Annuler
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.task-card {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-md);
  padding: 14px;
  position: relative;
  overflow: hidden;
}
.task-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
}
.task-pending::before     { background: var(--pms-gold); }
.task-in_progress::before { background: var(--pms-blue); }
.task-done::before        { background: var(--pms-green); }
.task-inspected::before   { background: var(--pms-teal); }
.task-skipped::before     { background: var(--pms-ink-3); }

.task-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.task-room {
  font-size: 20px;
  font-weight: 500;
  color: var(--pms-ink);
  font-family: var(--mono);
}
.task-room-type {
  font-size: 12px;
  color: var(--pms-ink-3);
  margin-top: 2px;
}

/* Type badges */
.badge-type {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 100px;
  font-size: 10px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.type-departure   { background: var(--pms-red-light); color: var(--pms-red); }
.type-stay_over   { background: var(--pms-gold-light); color: var(--pms-gold-dark); }
.type-inspection  { background: var(--pms-teal-light); color: var(--pms-teal-dark); }
.type-maintenance { background: var(--pms-blue-light); color: var(--pms-blue); }

/* Status badges */
.badge-status {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: 100px;
  font-size: 11px;
  font-weight: 500;
}
.badge-status .badge-dot { width: 5px; height: 5px; border-radius: 50%; }
.status-pending     { background: var(--pms-gold-light); color: var(--pms-gold-dark); }
.status-pending .badge-dot { background: var(--pms-gold); }
.status-in_progress { background: var(--pms-blue-light); color: var(--pms-blue); }
.status-in_progress .badge-dot { background: var(--pms-blue); }
.status-done        { background: var(--pms-green-light); color: var(--pms-green); }
.status-done .badge-dot { background: var(--pms-green); }
.status-inspected   { background: var(--pms-teal-light); color: var(--pms-teal-dark); }
.status-inspected .badge-dot { background: var(--pms-teal); }
.status-skipped     { background: var(--pms-sand-2); color: var(--pms-ink-3); }
.status-skipped .badge-dot { background: var(--pms-ink-3); }

/* Meta */
.task-meta {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 12px;
  color: var(--pms-ink-3);
}
.task-meta i { font-size: 14px; }
.task-assigned { display: flex; align-items: center; gap: 4px; }
.task-unassigned { font-style: italic; color: var(--pms-ink-3); }
.task-time { display: flex; align-items: center; gap: 4px; }

.task-notes {
  font-size: 12px;
  color: var(--pms-ink-3);
  margin-top: 6px;
  line-height: 1.4;
}

.task-error {
  font-size: 12px;
  color: var(--pms-red);
  margin-top: 8px;
  display: flex;
  align-items: center;
  gap: 4px;
}

/* Actions */
.task-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
}
.btn-action {
  flex: 1;
  height: 40px;
  font-size: 13px;
  justify-content: center;
}
.btn-action-sm {
  font-size: 12px;
  padding: 0 12px;
  height: 40px;
}
.task-done-badge {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--pms-teal);
  font-size: 13px;
  font-weight: 500;
}

/* Skip confirmation */
.skip-confirm {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 8px;
  padding: 8px 10px;
  background: var(--pms-red-light);
  border-radius: var(--radius-sm);
}
.skip-confirm-text {
  font-size: 12px;
  font-weight: 500;
  color: var(--pms-red);
}
.skip-confirm-actions {
  display: flex;
  gap: 6px;
}
</style>
