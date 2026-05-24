import api from './api.service'
import type { CleaningTask, CleaningStatus, ApiSuccess } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Service Housekeeping
// ──────────────────────────────────────────────────────────────

export const housekeepingService = {
  /**
   * GET /api/housekeeping/tasks?date=YYYY-MM-DD
   */
  async getTasks(date?: string): Promise<CleaningTask[]> {
    const { data } = await api.get<ApiSuccess<CleaningTask[]>>('/housekeeping/tasks', {
      params: date ? { date } : {},
    })
    return data.data
  },

  /**
   * PATCH /api/housekeeping/tasks/{id}/status
   */
  async updateStatus(id: string, status: CleaningStatus): Promise<CleaningTask> {
    const { data } = await api.patch<ApiSuccess<CleaningTask>>(
      `/housekeeping/tasks/${id}/status`,
      { status },
    )
    return data.data
  },

  /**
   * PATCH /api/housekeeping/tasks/{id}/assign
   */
  async assign(id: string, assignedToId: string | null): Promise<CleaningTask> {
    const { data } = await api.patch<ApiSuccess<CleaningTask>>(
      `/housekeeping/tasks/${id}/assign`,
      { assignedToId },
    )
    return data.data
  },
}
