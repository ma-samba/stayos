import api from './api.service'
import type { RatePlan, SeasonalRate, Promotion, PriceQuote, ApiSuccess } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  Service Tarifs & Promotions
// ──────────────────────────────────────────────────────────────

export const rateService = {
  // ── Rate Plans ──

  async listPlans(): Promise<RatePlan[]> {
    const { data } = await api.get<ApiSuccess<RatePlan[]>>('/rates/plans')
    return data.data
  },

  async createPlan(payload: Partial<RatePlan>): Promise<RatePlan> {
    const { data } = await api.post<ApiSuccess<RatePlan>>('/rates/plans', payload)
    return data.data
  },

  async updatePlan(id: string, payload: Partial<RatePlan>): Promise<RatePlan> {
    const { data } = await api.put<ApiSuccess<RatePlan>>(`/rates/plans/${id}`, payload)
    return data.data
  },

  async deletePlan(id: string): Promise<void> {
    await api.delete(`/rates/plans/${id}`)
  },

  // ── Seasonal Rates ──

  async listSeasonal(): Promise<SeasonalRate[]> {
    const { data } = await api.get<ApiSuccess<SeasonalRate[]>>('/rates/seasonal')
    return data.data
  },

  async createSeasonal(payload: Partial<SeasonalRate>): Promise<SeasonalRate> {
    const { data } = await api.post<ApiSuccess<SeasonalRate>>('/rates/seasonal', payload)
    return data.data
  },

  async updateSeasonal(id: string, payload: Partial<SeasonalRate>): Promise<SeasonalRate> {
    const { data } = await api.put<ApiSuccess<SeasonalRate>>(`/rates/seasonal/${id}`, payload)
    return data.data
  },

  async deleteSeasonal(id: string): Promise<void> {
    await api.delete(`/rates/seasonal/${id}`)
  },

  // ── Quote ──

  async quote(payload: {
    roomId: string
    checkIn: string
    checkOut: string
    ratePlanId?: string
    promoCode?: string
  }): Promise<PriceQuote> {
    const { data } = await api.post<ApiSuccess<PriceQuote>>('/rates/quote', payload)
    return data.data
  },

  // ── Promotions ──

  async listPromotions(): Promise<Promotion[]> {
    const { data } = await api.get<ApiSuccess<Promotion[]>>('/promotions')
    return data.data
  },

  async createPromotion(payload: Partial<Promotion>): Promise<Promotion> {
    const { data } = await api.post<ApiSuccess<Promotion>>('/promotions', payload)
    return data.data
  },

  async updatePromotion(id: string, payload: Partial<Promotion>): Promise<Promotion> {
    const { data } = await api.put<ApiSuccess<Promotion>>(`/promotions/${id}`, payload)
    return data.data
  },

  async deletePromotion(id: string): Promise<void> {
    await api.delete(`/promotions/${id}`)
  },
}
