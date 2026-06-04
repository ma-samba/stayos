import api from './api.service'
import type {
  ApiSuccess,
  CancelResponse,
  Plan,
  SaasInvoice,
  Subscription,
  UpgradeResponse,
} from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Service Abonnement SaaS — V1 sans store partagé : les vues
//  fetch directement. Si on en partage l'état à terme, on
//  extraira un store Pinia.
// ──────────────────────────────────────────────────────────────

export const subscriptionService = {
  /**
   * GET /api/subscription — plan courant + dates + usage.
   * Le backend renvoie 404 si aucun abonnement actif → null.
   */
  async getCurrent(): Promise<Subscription | null> {
    try {
      const { data } = await api.get<ApiSuccess<Subscription>>('/subscription')
      return data.data
    } catch (e: unknown) {
      if (
        e
        && typeof e === 'object'
        && 'response' in e
        && (e as { response?: { status?: number } }).response?.status === 404
      ) {
        return null
      }
      throw e
    }
  },

  /**
   * GET /api/subscription/plans — plans actifs, triés priceXof ASC.
   */
  async getPlans(): Promise<Plan[]> {
    const { data } = await api.get<ApiSuccess<Plan[]>>('/subscription/plans')
    return data.data
  },

  /**
   * POST /api/subscription/upgrade — bascule sur le plan choisi.
   * Trial → ACTIVE immédiat ; actif → garde la période en cours.
   */
  async upgrade(planId: number): Promise<UpgradeResponse> {
    const { data } = await api.post<ApiSuccess<UpgradeResponse>>(
      '/subscription/upgrade',
      { planId },
    )
    return data.data
  },

  /**
   * POST /api/subscription/cancel — passe en CANCELLED.
   * Accès maintenu jusqu'à currentPeriodEnd / trialEndsAt.
   */
  async cancel(): Promise<CancelResponse> {
    const { data } = await api.post<ApiSuccess<CancelResponse>>(
      '/subscription/cancel',
      {},
    )
    return data.data
  },

  /**
   * GET /api/subscription/invoices — historique SaasInvoice du tenant.
   */
  async getInvoices(): Promise<SaasInvoice[]> {
    const { data } = await api.get<ApiSuccess<SaasInvoice[]>>('/subscription/invoices')
    return data.data
  },
}
