import { defineStore } from 'pinia'
import { ref } from 'vue'
import { dashboardService } from '@/services/dashboard.service'
import { mercureService } from '@/services/mercure.service'
import { useAuthStore } from '@/stores/auth.store'
import type { DashboardKpis, PeriodReport } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Store Dashboard
//
//  Live update : on écoute payment.received et reservation.checkin,
//  mais on NE FETCH PAS à chaque event (un check-in déclenche déjà
//  payment.received via le facturage draft → on serait à 2 refetch
//  par opération). À la place, on déclenche un fetchToday() debouncé
//  à 2s : N events dans la même fenêtre = 1 seul appel API.
// ──────────────────────────────────────────────────────────────

const REFETCH_DEBOUNCE_MS = 2000

export const useDashboardStore = defineStore('dashboard', () => {
  const todayKpis     = ref<DashboardKpis | null>(null)
  const report        = ref<PeriodReport | null>(null)
  const loadingToday  = ref(false)
  const loadingReport = ref(false)
  const error         = ref<string | null>(null)

  // ── Refcount Mercure ──────────────────────────────────────
  let liveRefCount = 0
  const unsubscribers: Array<() => void> = []
  let refetchTimer: ReturnType<typeof setTimeout> | null = null

  async function fetchToday(): Promise<void> {
    loadingToday.value = true
    error.value = null
    try {
      todayKpis.value = await dashboardService.today()
    } catch {
      error.value = 'Impossible de charger les KPIs du jour.'
    } finally {
      loadingToday.value = false
    }
  }

  async function fetchReport(from: string, to: string): Promise<void> {
    loadingReport.value = true
    error.value = null
    try {
      report.value = await dashboardService.report(from, to)
    } catch {
      error.value = 'Impossible de charger le rapport.'
    } finally {
      loadingReport.value = false
    }
  }

  async function exportReport(from: string, to: string, format: 'csv' | 'xlsx' = 'csv'): Promise<void> {
    try {
      await dashboardService.exportReport(from, to, format)
    } catch {
      error.value = 'Erreur lors de l\'export du rapport.'
    }
  }

  function scheduleTodayRefetch(): void {
    if (refetchTimer) clearTimeout(refetchTimer)
    refetchTimer = setTimeout(() => {
      refetchTimer = null
      void fetchToday()
    }, REFETCH_DEBOUNCE_MS)
  }

  function subscribeLive(): void {
    liveRefCount++
    if (unsubscribers.length > 0) return

    const auth = useAuthStore()
    const tenantId = auth.tenantId
    if (!tenantId) return

    // Une seule EventSource multiplexée. Pas besoin de distinguer
    // les topics : peu importe lequel a fired, on debounce un refetch.
    const topics = [
      mercureService.buildTopic(tenantId, 'payment.received'),
      mercureService.buildTopic(tenantId, 'reservation.checkin'),
    ]

    unsubscribers.push(
      mercureService.subscribeMany(topics, () => scheduleTodayRefetch()),
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
    todayKpis,
    report,
    loadingToday,
    loadingReport,
    error,
    fetchToday,
    fetchReport,
    exportReport,
    subscribeLive,
    unsubscribeLive,
  }
})
