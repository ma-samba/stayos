import api from './api.service'
import type { DashboardKpis, PeriodReport, ApiSuccess } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Service Dashboard & Rapports
// ──────────────────────────────────────────────────────────────

export const dashboardService = {
  /**
   * GET /api/dashboard/today — KPIs du jour.
   */
  async today(): Promise<DashboardKpis> {
    const { data } = await api.get<ApiSuccess<DashboardKpis>>('/dashboard/today')
    return data.data
  },

  /**
   * GET /api/dashboard/report — Rapport sur période.
   */
  async report(from: string, to: string): Promise<PeriodReport> {
    const { data } = await api.get<ApiSuccess<PeriodReport>>('/dashboard/report', {
      params: { from, to },
    })
    return data.data
  },

  /**
   * GET /api/dashboard/report/export — Télécharger le rapport (blob).
   */
  async exportReport(from: string, to: string, format: 'csv' | 'xlsx' = 'csv'): Promise<void> {
    const res = await api.get('/dashboard/report/export', {
      params: { from, to, format },
      responseType: 'blob',
    })

    const blob = res.data as Blob
    const url  = window.URL.createObjectURL(blob)
    const a    = document.createElement('a')
    a.href     = url
    a.download = `rapport-${from}_${to}.${format}`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    setTimeout(() => window.URL.revokeObjectURL(url), 10_000)
  },
}
