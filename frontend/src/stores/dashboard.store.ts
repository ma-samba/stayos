import { defineStore } from 'pinia'
import { ref } from 'vue'
import { dashboardService } from '@/services/dashboard.service'
import type { DashboardKpis, PeriodReport } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Store Dashboard
// ──────────────────────────────────────────────────────────────

export const useDashboardStore = defineStore('dashboard', () => {
  const todayKpis     = ref<DashboardKpis | null>(null)
  const report        = ref<PeriodReport | null>(null)
  const loadingToday  = ref(false)
  const loadingReport = ref(false)
  const error         = ref<string | null>(null)

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

  return {
    todayKpis,
    report,
    loadingToday,
    loadingReport,
    error,
    fetchToday,
    fetchReport,
    exportReport,
  }
})
