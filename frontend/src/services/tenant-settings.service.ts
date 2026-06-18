import api from './api.service'
import type { TenantSettings } from '@/types/financial-policies'

interface ApiSuccess<T> { data: T; status: number; message?: string }

// ──────────────────────────────────────────────────────────────
//  Service Tenant Settings — Sprint 13quinquies-A
//  Endpoint léger pour les paramètres tenant (politiques + cutoff
//  + tz + currency). Lu une fois au mount des modales / vues.
// ──────────────────────────────────────────────────────────────

// ──────────────────────────────────────────────────────────────
//  Update — Sprint 14-A.2 (onglet Finances de la config hôtel)
//  Payload partiel : 1, 2 ou 3 champs parmi noShowPolicy,
//  cancellationPolicy, businessDayCutoffHour. Manager uniquement.
// ──────────────────────────────────────────────────────────────

export interface TenantSettingsUpdatePayload {
  noShowPolicy?: TenantSettings['noShowPolicy']
  cancellationPolicy?: TenantSettings['cancellationPolicy']
  businessDayCutoffHour?: TenantSettings['businessDayCutoffHour']
}

export const tenantSettingsService = {
  async get(): Promise<TenantSettings> {
    const { data } = await api.get<ApiSuccess<TenantSettings>>('/tenant/settings')
    return data.data
  },

  async update(payload: TenantSettingsUpdatePayload): Promise<TenantSettings> {
    const { data } = await api.patch<ApiSuccess<TenantSettings>>('/tenant/settings', payload)
    return data.data
  },
}
