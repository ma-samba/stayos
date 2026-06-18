import api from './api.service'
import type { Invoice, Payment, ApiSuccess } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Service Facturation
// ──────────────────────────────────────────────────────────────

export const invoiceService = {
  /**
   * GET /api/invoices — Liste avec filtre optionnel par statut.
   */
  async list(status?: string): Promise<Invoice[]> {
    const { data } = await api.get<ApiSuccess<Invoice[]>>('/invoices', {
      params: status ? { status } : {},
    })
    return data.data
  },

  /**
   * GET /api/invoices/by-reservation/{reservationId} — Facture liee a une reservation.
   * Retourne null si pas de facture (404).
   */
  async byReservation(reservationId: string): Promise<Invoice | null> {
    try {
      const { data } = await api.get<ApiSuccess<Invoice>>(`/invoices/by-reservation/${reservationId}`)
      return data.data
    } catch {
      return null
    }
  },

  /**
   * GET /api/invoices/{id} — Détail complet (lignes + paiements).
   */
  async getOne(id: string): Promise<Invoice> {
    const { data } = await api.get<ApiSuccess<Invoice>>(`/invoices/${id}`)
    return data.data
  },

  /**
   * POST /api/invoices/{id}/issue — Passer une facture draft → issued.
   */
  async issue(id: string): Promise<Invoice> {
    const { data } = await api.post<ApiSuccess<Invoice>>(`/invoices/${id}/issue`, {})
    return data.data
  },

  /**
   * POST /api/invoices/{id}/payments — Enregistrer un paiement manuel.
   */
  async recordPayment(
    id: string,
    payload: { method: string; amountXof: string; reference?: string; notes?: string },
  ): Promise<Payment> {
    const { data } = await api.post<ApiSuccess<Payment>>(`/invoices/${id}/payments`, payload)
    return data.data
  },

  /**
   * POST /api/invoices/{id}/refunds — Enregistrer un remboursement
   * (sortie de caisse). Sprint 13quinquies-B.
   *
   * `amountXof` doit être > 0 (saisie utilisateur). Le service backend
   * le négative avant persistance. La réponse contient l'invoice mise
   * à jour (statut recalculé) ET le Payment refund créé.
   */
  async refund(
    id: string,
    payload: { amountXof: string; method: string; reason: string },
  ): Promise<{ invoice: Invoice; refund: Payment }> {
    const { data } = await api.post<ApiSuccess<{ invoice: Invoice; refund: Payment }>>(
      `/invoices/${id}/refunds`,
      payload,
    )
    return data.data
  },

  /**
   * POST /api/invoices/{id}/checkout — Initier un checkout Paydunya.
   */
  async checkout(
    id: string,
    gateway: string,
    channels: string[],
  ): Promise<{ checkoutUrl: string; paymentId: string }> {
    const { data } = await api.post<ApiSuccess<{ checkoutUrl: string; paymentId: string }>>(
      `/invoices/${id}/checkout`,
      { gateway, channels },
    )
    return data.data
  },

  /**
   * POST /api/invoices/{id}/send — Envoyer la facture par email.
   */
  async send(id: string): Promise<{ sent: boolean; to: string }> {
    const { data } = await api.post<ApiSuccess<{ sent: boolean; to: string }>>(
      `/invoices/${id}/send`,
      {},
    )
    return data.data
  },

  /**
   * GET /api/invoices/{id}/pdf — Télécharger le PDF via blob (JWT requis).
   */
  async downloadPdf(id: string): Promise<void> {
    const res = await api.get(`/invoices/${id}/pdf`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(res.data as Blob)
    window.open(url, '_blank')
    setTimeout(() => window.URL.revokeObjectURL(url), 10_000)
  },
}
