<script setup lang="ts">
import { onMounted, onUnmounted, computed } from 'vue'
import { useAuthStore } from '@/stores/auth.store'
import { useHousekeepingStore } from '@/stores/housekeeping.store'
import type { CleaningStatus } from '@/types/entities'
import TaskCard from '../components/TaskCard.vue'

const auth  = useAuthStore()
const store = useHousekeepingStore()

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

function onDateChange(): void {
  void store.fetchTasks()
}

// ── Lifecycle ──

onMounted(() => {
  void store.fetchTasks()
  store.subscribeLive()
})

onUnmounted(() => {
  store.unsubscribeLive()
})
</script>

<template>
  <div class="hk-page">

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
          :value="store.selectedDate"
          @change="(e) => { store.selectedDate = (e.target as HTMLInputElement).value; onDateChange() }"
        />
      </div>
    </div>

    <!-- ── Stat cards ── -->
    <div class="hk-stats">
      <div class="stat-card">
        <div class="stat-label">Total</div>
        <div class="stat-value">{{ store.totalCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">À faire</div>
        <div class="stat-value" style="color:var(--pms-gold-dark);">{{ store.pendingCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Terminées</div>
        <div class="stat-value" style="color:var(--pms-green);">{{ store.doneCount }}</div>
      </div>
    </div>

    <!-- ── Loading ── -->
    <div v-if="store.loading" class="hk-loading">
      <div class="spinner"></div>
    </div>

    <!-- ── Error ── -->
    <div v-else-if="store.error" class="empty-state">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <div>{{ store.error }}</div>
      <button class="btn btn-secondary btn-sm" @click="store.fetchTasks()">Réessayer</button>
    </div>

    <!-- ── Empty ── -->
    <div v-else-if="store.tasks.length === 0" class="empty-state">
      <i class="ti ti-sparkles" aria-hidden="true"></i>
      <div>Aucune tâche pour cette date</div>
    </div>

    <!-- ── Kanban ── -->
    <div v-else class="hk-board">
      <div v-for="col in columns" :key="col.key" :class="['hk-column', col.key === 'skipped' ? 'hk-column-muted' : '']">
        <div class="hk-col-header">
          <span class="hk-col-title">{{ col.label }}</span>
          <span class="hk-col-count">{{ store.tasksForStatus(col.key).length }}</span>
        </div>
        <div class="hk-col-body">
          <TaskCard
            v-for="task in store.tasksForStatus(col.key)"
            :key="task.id"
            :task="task"
            :is-supervisor="isSupervisor"
            @refresh="store.fetchTasks()"
          />
          <div v-if="store.tasksForStatus(col.key).length === 0" class="hk-col-empty">
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
