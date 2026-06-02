import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { housekeepingService } from '@/services/housekeeping.service'
import { mercureService } from '@/services/mercure.service'
import { useAuthStore } from '@/stores/auth.store'
import type {
  CleaningTask,
  CleaningStatus,
} from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Store Housekeeping (Kanban tâches ménage)
//  Subscribe live → patch in place sur task.assigned/updated.
//  Pas de refetch sur event Mercure : on travaille sur l'état
//  en mémoire pour éviter de mitrailler l'API.
// ──────────────────────────────────────────────────────────────

function todayIso(): string {
  return new Date().toISOString().slice(0, 10)
}

export const useHousekeepingStore = defineStore('housekeeping', () => {
  const tasks        = ref<CleaningTask[]>([])
  const selectedDate = ref<string>(todayIso())
  const loading      = ref(false)
  const error        = ref<string | null>(null)

  // ── Refcount Mercure ──────────────────────────────────────
  let liveRefCount = 0
  const unsubscribers: Array<() => void> = []

  // ── Computed ──────────────────────────────────────────────

  const totalCount   = computed(() => tasks.value.length)
  const pendingCount = computed(() => tasks.value.filter(t => t.status === 'pending').length)
  const doneCount    = computed(() => tasks.value.filter(t => t.status === 'done' || t.status === 'inspected').length)

  function tasksForStatus(status: CleaningStatus): CleaningTask[] {
    return tasks.value.filter(t => t.status === status)
  }

  // ── Actions ───────────────────────────────────────────────

  async function fetchTasks(date?: string): Promise<void> {
    if (date) selectedDate.value = date
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

  /**
   * Patch local : merge des champs reçus dans la tâche existante.
   * Si la tâche n'est pas dans la liste courante (autre date par ex.),
   * on ignore — le filtre par date dicte ce qu'on affiche.
   */
  function patchTaskLocal(taskId: string, patch: Partial<CleaningTask>): void {
    const i = tasks.value.findIndex(t => t.id === taskId)
    if (i === -1) return
    tasks.value[i] = { ...tasks.value[i], ...patch }
  }

  // ── Mercure ───────────────────────────────────────────────

  function subscribeLive(): void {
    liveRefCount++
    if (unsubscribers.length > 0) return

    const auth = useAuthStore()
    const tenantId = auth.tenantId
    if (!tenantId) return

    // Une seule EventSource multiplexée pour les 2 topics.
    // Distinction via la présence de `assignedToId` (task.assigned
    // l'inclut toujours, task.updated jamais).
    const topics = [
      mercureService.buildTopic(tenantId, 'task.assigned'),
      mercureService.buildTopic(tenantId, 'task.updated'),
    ]

    unsubscribers.push(
      mercureService.subscribeMany<Record<string, unknown>>(topics, (data) => {
        const taskId = typeof data.taskId === 'string' ? data.taskId : null
        if (!taskId) return

        if (data.assignedToId !== undefined) {
          patchTaskLocal(taskId, {
            assignedToId:   data.assignedToId as string,
            assignedToName: data.assignedToName as string,
          })
        } else if (data.status !== undefined) {
          patchTaskLocal(taskId, { status: data.status as CleaningStatus })
        }
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
    }
  }

  return {
    tasks,
    selectedDate,
    loading,
    error,
    totalCount,
    pendingCount,
    doneCount,
    tasksForStatus,
    fetchTasks,
    patchTaskLocal,
    subscribeLive,
    unsubscribeLive,
  }
})
