<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth.store'
import { housekeepingService } from '@/services/housekeeping.service'
import { mercureService } from '@/services/mercure.service'
import type { CleaningTask, CleaningStatus, TaskAssignedEvent, TaskUpdatedEvent } from '@/types/entities'
import TaskCard from '../components/TaskCard.vue'

const auth = useAuthStore()

// ── State ──

const tasks   = ref<CleaningTask[]>([])
const loading = ref(true)
const error   = ref<string | null>(null)

const today = new Date().toISOString().slice(0, 10)
const selectedDate = ref(today)

// ── Role ──

const isSupervisor = computed(() =>
  auth.userRole === 'MANAGER' || auth.userRole === 'RECEPTIONIST',
)

// ── Columns ──

const columns: { key: CleaningStatus; label: string }[] = [
  { key: 'pending',     label: 'À faire' },
  { key: 'in_progress', label: 'En cours' },
  { key: 'done',        label: 'Terminé' },
  { key: 'inspected',   label: 'Inspecté' },
  { key: 'skipped',     label: 'Ignoré' },
]

function tasksForStatus(status: CleaningStatus): CleaningTask[] {
  return tasks.value.filter(t => t.status === status)
}

// ── Stats ──

const totalCount   = computed(() => tasks.value.length)
const pendingCount = computed(() => tasks.value.filter(t => t.status === 'pending').length)
const doneCount    = computed(() => tasks.value.filter(t => t.status === 'done' || t.status === 'inspected').length)

// ── Fetch ──

async function fetchTasks(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    tasks.value = await housekeepingService.getTasks(selectedDate.value)
  } catch {
    error.value = 'Impossible de charger les tâches'
  } finally {
    loading.value = false
  }
}

function onDateChange(): void {
  fetchTasks()
}

// ── Mercure ──

let unsubAssigned: (() => void) | null = null
let unsubUpdated: (() => void) | null = null

function setupMercure(): void {
  const tenantId = auth.tenantId
  if (!tenantId) return

  const topicAssigned = mercureService.buildTopic(tenantId, 'task.assigned')
  const topicUpdated  = mercureService.buildTopic(tenantId, 'task.updated')

  unsubAssigned = mercureService.subscribe<TaskAssignedEvent>(topicAssigned, (data) => {
    // If this task is assigned to me, show a notification
    if (data.assignedToId === auth.userId) {
      showNotification(`Nouvelle tâche : chambre ${data.roomNumber}`)
    }
    fetchTasks()
  })

  unsubUpdated = mercureService.subscribe<TaskUpdatedEvent>(topicUpdated, () => {
    fetchTasks()
  })
}

function teardownMercure(): void {
  unsubAssigned?.()
  unsubUpdated?.()
  unsubAssigned = null
  unsubUpdated = null
}

// ── Notification toast ──

const notification = ref<string | null>(null)
let notifTimeout: ReturnType<typeof setTimeout> | null = null

function showNotification(msg: string): void {
  notification.value = msg
  if (notifTimeout) clearTimeout(notifTimeout)
  notifTimeout = setTimeout(() => { notification.value = null }, 5000)
}

// ── Lifecycle ──

onMounted(() => {
  fetchTasks()
  setupMercure()
})

onUnmounted(() => {
  teardownMercure()
  if (notifTimeout) clearTimeout(notifTimeout)
})
</script>

<template>
  <div class="hk-page">

    <!-- Notification toast -->
    <Transition name="toast">
      <div v-if="notification" class="toast toast-info">
        <i class="ti ti-bell" aria-hidden="true"></i> {{ notification }}
      </div>
    </Transition>

    <!-- ── Header ── -->
    <div class="hk-header">
      <div>
        <h1 class="hk-title">Ménage</h1>
        <p class="t-muted">Suivi des tâches de nettoyage</p>
      </div>
      <div class="hk-date-picker">
        <input
          type="date"
          class="input"
          :value="selectedDate"
          @change="(e) => { selectedDate = (e.target as HTMLInputElement).value; onDateChange() }"
        />
      </div>
    </div>

    <!-- ── Stat cards ── -->
    <div class="hk-stats">
      <div class="stat-card">
        <div class="stat-label">Total</div>
        <div class="stat-value">{{ totalCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">À faire</div>
        <div class="stat-value" style="color:var(--pms-gold-dark);">{{ pendingCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Terminées</div>
        <div class="stat-value" style="color:var(--pms-green);">{{ doneCount }}</div>
      </div>
    </div>

    <!-- ── Loading ── -->
    <div v-if="loading" class="hk-loading">
      <div class="spinner"></div>
    </div>

    <!-- ── Error ── -->
    <div v-else-if="error" class="empty-state">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <div>{{ error }}</div>
      <button class="btn btn-secondary btn-sm" @click="fetchTasks()">Réessayer</button>
    </div>

    <!-- ── Empty ── -->
    <div v-else-if="tasks.length === 0" class="empty-state">
      <i class="ti ti-sparkles" aria-hidden="true"></i>
      <div>Aucune tâche pour cette date</div>
    </div>

    <!-- ── Kanban ── -->
    <div v-else class="hk-board">
      <div v-for="col in columns" :key="col.key" :class="['hk-column', col.key === 'skipped' ? 'hk-column-muted' : '']">
        <div class="hk-col-header">
          <span class="hk-col-title">{{ col.label }}</span>
          <span class="hk-col-count">{{ tasksForStatus(col.key).length }}</span>
        </div>
        <div class="hk-col-body">
          <TaskCard
            v-for="task in tasksForStatus(col.key)"
            :key="task.id"
            :task="task"
            :is-supervisor="isSupervisor"
            @refresh="fetchTasks()"
          />
          <div v-if="tasksForStatus(col.key).length === 0" class="hk-col-empty">
            Aucune tâche
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.hk-page {
  padding: 1.5rem;
  max-width: 1600px;
  margin: 0 auto;
  position: relative;
}

/* ── Header ── */
.hk-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1.5rem;
  gap: 16px;
  flex-wrap: wrap;
}
.hk-title {
  font-size: 22px;
  font-weight: 500;
  color: var(--pms-ink);
  margin-bottom: 4px;
}
.hk-date-picker .input {
  width: 180px;
}

/* ── Stats ── */
.hk-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 12px;
  margin-bottom: 1.5rem;
}

/* ── Loading ── */
.hk-loading {
  display: flex;
  justify-content: center;
  padding: 4rem 0;
}

/* ── Board ── */
.hk-board {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 14px;
  align-items: flex-start;
}

.hk-column {
  background: var(--pms-sand);
  border-radius: var(--radius-lg);
  padding: 12px;
  min-height: 200px;
}
.hk-column-muted {
  opacity: 0.7;
}

.hk-col-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  padding: 0 4px;
}
.hk-col-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--pms-ink);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.hk-col-count {
  background: var(--pms-sand-2);
  color: var(--pms-ink-3);
  font-size: 11px;
  font-weight: 500;
  padding: 2px 8px;
  border-radius: 100px;
}

.hk-col-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.hk-col-empty {
  text-align: center;
  font-size: 12px;
  color: var(--pms-ink-3);
  padding: 2rem 0;
  font-style: italic;
}

/* ── Toast notification ── */
.toast {
  position: fixed;
  top: 1.5rem;
  right: 1.5rem;
  z-index: 1000;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 20px;
  border-radius: var(--radius-md);
  font-size: 13px;
  font-weight: 500;
  box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}
.toast-info {
  background: var(--pms-teal);
  color: #fff;
}
.toast-enter-active,
.toast-leave-active {
  transition: all 0.3s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-12px);
}

/* ── Responsive ── */
@media (max-width: 1280px) {
  .hk-board {
    grid-template-columns: repeat(3, 1fr);
  }
}
@media (max-width: 1024px) {
  .hk-board {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .hk-page { padding: 1rem; }
  .hk-board {
    grid-template-columns: 1fr;
  }
  .hk-column { min-height: auto; }
  .hk-header { flex-direction: column; }
  .hk-date-picker .input { width: 100%; }
}
</style>
