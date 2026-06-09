import api from './api.service'
import type {
  NightAuditCurrent,
  NightAuditChecklist,
  DailyClose,
  DailyCloseListResponse,
} from '@/types/night-audit'

// ──────────────────────────────────────────────────────────────
//  Service Night Audit — Sprint 13quater-C
// ──────────────────────────────────────────────────────────────

interface ApiSuccess<T> { data: T; status: number; message?: string }

export const nightAuditService = {
  async getCurrent(): Promise<NightAuditCurrent> {
    const { data } = await api.get<ApiSuccess<NightAuditCurrent>>('/night-audit/current')
    return data.data
  },

  async getChecklist(): Promise<NightAuditChecklist> {
    const { data } = await api.get<ApiSuccess<NightAuditChecklist>>('/night-audit/checklist')
    return data.data
  },

  async list(page = 1, perPage = 20): Promise<DailyCloseListResponse> {
    const { data } = await api.get<DailyCloseListResponse>(
      `/night-audit?page=${page}&perPage=${perPage}`,
    )
    return data
  },

  async getOne(id: string): Promise<DailyClose> {
    const { data } = await api.get<ApiSuccess<DailyClose>>(`/night-audit/${id}`)
    return data.data
  },

  async close(force = false): Promise<DailyClose> {
    const { data } = await api.post<ApiSuccess<DailyClose>>('/night-audit/close', { force })
    return data.data
  },

  async reopen(id: string, reason: string): Promise<DailyClose> {
    const { data } = await api.post<ApiSuccess<DailyClose>>(
      `/night-audit/${id}/reopen`,
      { reason },
    )
    return data.data
  },

  /**
   * Télécharge le PDF en blob et déclenche un download navigateur.
   * `responseType: 'blob'` est crucial pour ne pas que axios tente
   * un parse JSON.
   */
  async downloadPdf(id: string, businessDate: string): Promise<void> {
    const response = await api.get(`/night-audit/${id}/pdf`, {
      responseType: 'blob',
    })
    const blob = response.data as Blob
    const url = URL.createObjectURL(blob)
    try {
      const link = document.createElement('a')
      link.href = url
      link.download = `cloture-${businessDate}.pdf`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
    } finally {
      URL.revokeObjectURL(url)
    }
  },
}
