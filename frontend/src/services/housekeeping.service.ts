import api from './api.service'
import type { CleaningTask, CleaningStatus, StaffUser, ApiSuccess } from '@/types/entities'

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

  /**
   * GET /api/staff?role=ROLE_HOUSEKEEPER — utilisé par le sélecteur
   * d'assignation. Lazy-load au moment de l'ouverture du popover, pas
   * de cache global en V1 (V2 si le volume d'appels devient gênant).
   */
  async listHousekeepers(): Promise<StaffUser[]> {
    const { data } = await api.get<ApiSuccess<StaffUser[]>>('/staff', {
      params: { role: 'ROLE_HOUSEKEEPER' },
    })
    return data.data
  },
}
