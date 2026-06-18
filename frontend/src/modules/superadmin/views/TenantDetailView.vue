<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { superadminService } from '@/services/superadmin.service'
import type { TenantDetail, TenantStatus } from '@/types/superadmin'
import { formatCurrency } from '@/utils/currency'
import { useNotificationsStore } from '@/stores/notifications.store'

const notif = useNotificationsStore()

const router = useRouter()
const route  = useRoute()

const slug    = route.params.slug as string
const tenant  = ref<TenantDetail | null>(null)
const loading = ref(true)
const error   = ref<string | null>(null)

const showSuspendConfirm   = ref(false)
const suspendReason        = ref('')
const showReactivateConfirm = ref(false)
const actionLoading        = ref(false)
const actionError          = ref<string | null>(null)

// ── Édition ──
const editing       = ref(false)
const editName      = ref('')
const editTimezone  = ref('')
const editCountry   = ref('')
const editCurrency  = ref('')

// ── Force plan ──
const showForcePlan = ref(false)
const forcePlanValue       = ref<'STARTER' | 'PRO' | 'ENTERPRISE'>('PRO')
const forcePlanReason      = ref('')
const forcePlanPeriodEnd   = ref('')

const STATUS_LABEL: Record<TenantStatus, string> = {
  active:    'Actif',
  trial:     'Essai',
  suspended: 'Suspendu',
  churned:   'Désabonné',
}

const isSuspended = computed(() => tenant.value?.status === 'suspended')

async function fetchTenant(): Promise<void> {
  loading.value = true
  error.value   = null
  try {
    tenant.value = await superadminService.getTenant(slug)
    // Pré-remplir le formulaire d'édition à chaque fetch
    if (tenant.value) {
      editName.value     = tenant.value.name
      editTimezone.value = (tenant.value as unknown as { timezone?: string }).timezone ?? 'Africa/Dakar'
      editCountry.value  = tenant.value.country
      editCurrency.value = tenant.value.currency
    }
  } catch (e: unknown) {
    const status = (e as { response?: { status?: number } }).response?.status
    if (status === 404) {
      error.value = 'Ce tenant est introuvable.'
    } else {
      error.value = 'Impossible de charger ce tenant.'
    }
    console.error(e)
  } finally {
    loading.value = false
  }
}

async function saveEdit(): Promise<void> {
  if (!tenant.value) return
  actionLoading.value = true
  actionError.value   = null
  try {
    await superadminService.updateTenant(tenant.value.slug, {
      name:     editName.value.trim(),
      timezone: editTimezone.value.trim(),
      country:  editCountry.value.trim(),
      currency: editCurrency.value.trim(),
    })
    editing.value = false
    await fetchTenant()
    notif.pushUiToast('success', 'Tenant modifié.')
  } catch (e: unknown) {
    const resp = (e as { response?: { status?: number; data?: { error?: string } } }).response
    actionError.value = resp?.data?.error ?? 'Erreur lors de la modification.'
  } finally {
    actionLoading.value = false
  }
}

function cancelEdit(): void {
  editing.value     = false
  actionError.value = null
  // Re-sync depuis tenant
  if (tenant.value) {
    editName.value     = tenant.value.name
    editCountry.value  = tenant.value.country
    editCurrency.value = tenant.value.currency
  }
}

async function applyForcePlan(): Promise<void> {
  if (!tenant.value) return
  if (forcePlanReason.value.trim().length < 5) {
    actionError.value = 'La raison doit faire au moins 5 caractères.'
    return
  }
  actionLoading.value = true
  actionError.value   = null
  try {
    await superadminService.forcePlan(tenant.value.slug, {
      plan:           forcePlanValue.value,
      reason:         forcePlanReason.value.trim(),
      new_period_end: forcePlanPeriodEnd.value || undefined,
    })
    showForcePlan.value      = false
    forcePlanReason.value    = ''
    forcePlanPeriodEnd.value = ''
    await fetchTenant()
    notif.pushUiToast('success', 'Plan forcé. Subscription mise à jour.')
  } catch (e: unknown) {
    const resp = (e as { response?: { status?: number; data?: { error?: string } } }).response
    actionError.value = resp?.data?.error ?? 'Erreur lors du changement de plan.'
  } finally {
    actionLoading.value = false
  }
}

async function suspendNow(): Promise<void> {
  if (!tenant.value) return
  actionLoading.value = true
  actionError.value   = null
  try {
    await superadminService.suspendTenant(tenant.value.slug, suspendReason.value.trim() || undefined)
    await fetchTenant()
    showSuspendConfirm.value = false
    suspendReason.value      = ''
    notif.pushUiToast('success', 'Tenant suspendu. L\'accès est bloqué jusqu\'à réactivation.')
  } catch (e: unknown) {
    const resp = (e as { response?: { status?: number; data?: { error?: string } } }).response
    if (resp?.status === 422) {
      actionError.value = resp.data?.error ?? 'Action impossible dans l\'état actuel.'
    } else {
      actionError.value = 'Erreur lors de la suspension.'
    }
  } finally {
    actionLoading.value = false
  }
}

async function reactivateNow(): Promise<void> {
  if (!tenant.value) return
  actionLoading.value = true
  actionError.value   = null
  try {
    await superadminService.reactivateTenant(tenant.value.slug)
    await fetchTenant()
    showReactivateConfirm.value = false
    notif.pushUiToast('success', 'Tenant réactivé. L\'accès est rétabli.')
  } catch (e: unknown) {
    const resp = (e as { response?: { status?: number; data?: { error?: string } } }).response
    if (resp?.status === 422) {
      actionError.value = resp.data?.error ?? 'Action impossible dans l\'état actuel.'
    } else {
      actionError.value = 'Erreur lors de la réactivation.'
    }
  } finally {
    actionLoading.value = false
  }
}

function formatDateTime(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('fr-SN', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('fr-SN', {
    day: '2-digit', month: 'short', year: 'numeric',
  })
}

onMounted(fetchTenant)
</script>

<template>
  <div class="sa-page">
    <button class="sa-back" @click="router.push('/superadmin/tenants')">
      <i class="ti ti-arrow-left"></i> Liste des tenants
    </button>

    <div v-if="loading" class="sa-loading">Chargement…</div>

    <div v-else-if="error" class="sa-error">
      <i class="ti ti-alert-circle"></i> {{ error }}
    </div>

    <template v-else-if="tenant">
      <!-- Header -->
      <header class="sa-page-head">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
          <h1>{{ tenant.name }}</h1>
          <span :class="['sa-badge', `sa-badge--${tenant.status}`]">
            <span class="sa-dot"></span>
            {{ STATUS_LABEL[tenant.status] }}
          </span>
        </div>
        <p class="t-muted">
          <span class="t-mono">{{ tenant.slug }}</span>
          · {{ tenant.subdomain }}.getstayos.com
        </p>
      </header>

      <!-- Grille principale -->
      <div class="sa-grid">
<!-- Informations -->
        <section class="card">
          <div class="sa-section-head">
            <h2 class="sa-section-title">Informations</h2>
            <button
              v-if="!editing"
              class="btn btn-ghost btn-sm"
              @click="editing = true; actionError = null"
            >
              <i class="ti ti-edit"></i> Modifier
            </button>
          </div>

          <template v-if="!editing">
            <dl class="sa-kv">
              <dt>Nom</dt>            <dd>{{ tenant.name }}</dd>
              <dt>Slug</dt>           <dd><span class="t-mono">{{ tenant.slug }}</span></dd>
              <dt>Subdomain</dt>      <dd>{{ tenant.subdomain }}</dd>
              <dt>Pays</dt>           <dd>{{ tenant.country }}</dd>
              <dt>Devise</dt>         <dd>{{ tenant.currency }}</dd>
              <dt>Créé le</dt>        <dd>{{ formatDateTime(tenant.createdAt) }}</dd>
            </dl>
          </template>

          <template v-else>
            <div class="input-wrap">
              <label class="input-label">Nom</label>
              <input v-model="editName" class="input" type="text" />
            </div>
            <div class="input-wrap">
              <label class="input-label">Timezone</label>
              <input v-model="editTimezone" class="input" type="text" />
            </div>
            <div style="display:flex; gap:10px;">
              <div class="input-wrap" style="flex:1;">
                <label class="input-label">Pays (ISO-2)</label>
                <input v-model="editCountry" class="input" type="text" maxlength="2" />
              </div>
              <div class="input-wrap" style="flex:1;">
                <label class="input-label">Devise (ISO-3)</label>
                <input v-model="editCurrency" class="input" type="text" maxlength="3" />
              </div>
            </div>
            <p class="sa-edit-hint">
              <i class="ti ti-info-circle"></i>
              Slug et subdomain sont figés pour préserver l'historique et les liens de paiement.
            </p>
            <div v-if="actionError" class="sa-error" style="margin-top:8px;">
              <i class="ti ti-alert-circle"></i> {{ actionError }}
            </div>
            <div style="display:flex; gap:8px; margin-top:10px;">
              <button class="btn btn-primary btn-sm" :disabled="actionLoading" @click="saveEdit">
                Enregistrer
              </button>
              <button class="btn btn-ghost btn-sm" :disabled="actionLoading" @click="cancelEdit">
                Annuler
              </button>
            </div>
          </template>
        </section>

        <!-- Abonnement -->
        <section class="card">
          <h2 class="sa-section-title">Abonnement</h2>
          <template v-if="tenant.subscription">
            <dl class="sa-kv">
              <dt>Plan</dt>           <dd>{{ tenant.subscription.plan }}</dd>
              <dt>Statut</dt>         <dd>{{ tenant.subscription.status }}</dd>
              <dt>Cycle</dt>          <dd>{{ tenant.subscription.billingCycle }}</dd>
              <dt v-if="tenant.subscription.trialEndsAt">Essai jusqu'au</dt>
              <dd v-if="tenant.subscription.trialEndsAt">{{ formatDate(tenant.subscription.trialEndsAt) }}</dd>
              <dt v-if="tenant.subscription.currentPeriodStart">Période début</dt>
              <dd v-if="tenant.subscription.currentPeriodStart">{{ formatDate(tenant.subscription.currentPeriodStart) }}</dd>
              <dt v-if="tenant.subscription.currentPeriodEnd">Période fin</dt>
              <dd v-if="tenant.subscription.currentPeriodEnd">{{ formatDate(tenant.subscription.currentPeriodEnd) }}</dd>
              <dt v-if="tenant.subscription.cancelledAt">Annulé le</dt>
              <dd v-if="tenant.subscription.cancelledAt">{{ formatDateTime(tenant.subscription.cancelledAt) }}</dd>
            </dl>
          </template>
          <p v-else class="t-muted">Aucun abonnement enregistré.</p>
        </section>

        <!-- Actions -->
        <section class="card sa-card-actions">
          <h2 class="sa-section-title">Actions</h2>

          <!-- Suspendre -->
          <template v-if="!isSuspended">
            <p class="t-muted" style="margin-bottom:12px;">
              Suspendre ce tenant bloque immédiatement l'accès à son hôtel
              (le manager reçoit une réponse 402 sur toute requête API).
            </p>
            <template v-if="!showSuspendConfirm">
              <button class="btn btn-danger" @click="showSuspendConfirm = true; actionError = null">
                <i class="ti ti-ban"></i> Suspendre ce tenant
              </button>
            </template>
            <template v-else>
              <label class="input-label">Raison (optionnel, pour audit)</label>
              <textarea
                v-model="suspendReason"
                class="input sa-textarea"
                rows="3"
                placeholder="Ex : Impayé constaté le 15/05, relances sans réponse."
              ></textarea>
              <div v-if="actionError" class="sa-error" style="margin-top:8px;">
                <i class="ti ti-alert-circle"></i> {{ actionError }}
              </div>
              <div style="display:flex; gap:8px; margin-top:10px;">
                <button class="btn btn-danger" :disabled="actionLoading" @click="suspendNow">
                  Confirmer la suspension
                </button>
                <button class="btn btn-ghost" :disabled="actionLoading" @click="showSuspendConfirm = false; actionError = null; suspendReason = ''">
                  Annuler
                </button>
              </div>
            </template>
          </template>

          <!-- Réactiver -->
          <template v-else>
            <p class="t-muted" style="margin-bottom:12px;">
              Ce tenant est actuellement suspendu. Le rétablir réouvre
              l'accès du staff de l'hôtel.
            </p>
            <template v-if="!showReactivateConfirm">
              <button class="btn btn-primary" @click="showReactivateConfirm = true; actionError = null">
                <i class="ti ti-circle-check"></i> Réactiver ce tenant
              </button>
            </template>
            <template v-else>
              <p style="font-size:13px; color:var(--pms-ink-2); margin-bottom:10px;">
                Confirmer la réactivation de <strong>{{ tenant.name }}</strong> ?
              </p>
              <div v-if="actionError" class="sa-error">
                <i class="ti ti-alert-circle"></i> {{ actionError }}
              </div>
              <div style="display:flex; gap:8px;">
                <button class="btn btn-primary" :disabled="actionLoading" @click="reactivateNow">
                  Confirmer la réactivation
                </button>
                <button class="btn btn-ghost" :disabled="actionLoading" @click="showReactivateConfirm = false; actionError = null">
                  Annuler
                </button>
              </div>
            </template>
          </template>
        </section>
      </div>

      <!-- Forcer un plan (collapsible) -->
      <section class="card" style="margin-top:1rem;">
        <button class="sa-collapsible-toggle" @click="showForcePlan = !showForcePlan">
          <i :class="showForcePlan ? 'ti ti-chevron-down' : 'ti ti-chevron-right'"></i>
          <span>Forcer un plan</span>
          <small class="t-muted" style="margin-left:8px;">— geste commercial, hors processus de paiement</small>
        </button>

        <div v-if="showForcePlan" class="sa-collapsible-body">
          <p class="t-muted" style="margin-bottom:1rem;">
            Force le plan de la subscription active. Aucune facture n'est
            émise (l'opérateur facture en off). L'action est tracée en clair
            dans l'audit log avec la raison ci-dessous.
          </p>

          <div style="display:flex; gap:10px;">
            <div class="input-wrap" style="flex:1;">
              <label class="input-label">Nouveau plan</label>
              <select v-model="forcePlanValue" class="input">
                <option value="STARTER">Starter</option>
                <option value="PRO">Pro</option>
                <option value="ENTERPRISE">Enterprise</option>
              </select>
            </div>
            <div class="input-wrap" style="flex:1;">
              <label class="input-label">Fin de période (optionnel)</label>
              <input v-model="forcePlanPeriodEnd" class="input" type="date" />
            </div>
          </div>

          <div class="input-wrap">
            <label class="input-label">Raison (audit, 5 caractères min)</label>
            <textarea
              v-model="forcePlanReason"
              class="input sa-textarea"
              rows="2"
              placeholder="Ex : Démo client grand compte X — facturation manuelle Q3."
            ></textarea>
          </div>

          <div v-if="actionError" class="sa-error" style="margin-bottom:8px;">
            <i class="ti ti-alert-circle"></i> {{ actionError }}
          </div>

          <button
            class="btn btn-gold"
            :disabled="actionLoading || forcePlanReason.trim().length < 5"
            @click="applyForcePlan"
          >
            <i class="ti ti-bolt"></i> Forcer ce plan
          </button>
        </div>
      </section>

      <!-- Factures SaaS récentes -->
      <section class="card" style="margin-top:1rem;">
        <h2 class="sa-section-title">Factures SaaS récentes</h2>
        <div v-if="tenant.recentInvoices.length === 0" class="t-muted" style="padding:0.5rem 0;">
          Aucune facture émise pour ce tenant.
        </div>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Numéro</th>
                <th>Plan</th>
                <th>Montant</th>
                <th>Statut</th>
                <th>Émise</th>
                <th>Réglée</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in tenant.recentInvoices" :key="inv.id">
                <td><span class="t-mono">{{ inv.number }}</span></td>
                <td>{{ inv.planName }}</td>
                <td style="font-weight:500;">{{ formatCurrency(inv.amountXof) }}</td>
                <td>{{ inv.status }}</td>
                <td>{{ formatDate(inv.createdAt) }}</td>
                <td>{{ formatDate(inv.paidAt) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.sa-page {
  padding: 1.5rem;
  max-width: 1400px;
  margin: 0 auto;
}

.sa-back {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: transparent;
  border: none;
  color: var(--pms-ink-3);
  font-family: var(--font);
  font-size: 13px;
  margin-bottom: 1rem;
  cursor: pointer;
  padding: 0;
}
.sa-back:hover { color: var(--pms-ink); }

.sa-page-head {
  margin-bottom: 1.5rem;
}
.sa-page-head h1 {
  font-size: 22px;
  font-weight: 500;
  color: var(--pms-ink);
  margin: 0;
}
.t-muted {
  color: var(--pms-ink-3);
  font-size: 13px;
}
.t-mono {
  font-family: var(--mono);
  font-size: 12px;
  color: var(--pms-teal);
}

.sa-error {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--pms-red-light);
  color: var(--pms-red);
  padding: 10px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
}
.sa-error i { font-size: 16px; }

.sa-loading {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  padding: 3rem;
  text-align: center;
  color: var(--pms-ink-3);
}

.sa-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 1rem;
}

.card {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  padding: 1.25rem 1.5rem;
}

.sa-section-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--pms-ink);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin: 0 0 1rem;
}

.sa-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}
.sa-section-head .sa-section-title { margin: 0; }

.sa-edit-hint {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  font-size: 11px;
  color: var(--pms-ink-3);
  margin-top: 4px;
}
.sa-edit-hint i { font-size: 14px; margin-top: 1px; }

.sa-collapsible-toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  background: transparent;
  border: none;
  padding: 0;
  font-family: var(--font);
  font-size: 13px;
  font-weight: 500;
  color: var(--pms-ink);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  cursor: pointer;
}
.sa-collapsible-toggle small {
  text-transform: none;
  letter-spacing: 0;
  font-weight: 400;
  font-size: 12px;
}
.sa-collapsible-toggle i { font-size: 16px; }

.sa-collapsible-body {
  margin-top: 1rem;
}

.sa-kv {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 8px 1rem;
  font-size: 13px;
  margin: 0;
}
.sa-kv dt {
  color: var(--pms-ink-3);
  font-weight: 400;
}
.sa-kv dd {
  margin: 0;
  color: var(--pms-ink-2);
}

.sa-card-actions {
  border-color: var(--pms-border-2);
}

.input-label {
  display: block;
  font-size: 11px;
  font-weight: 500;
  color: var(--pms-ink-3);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin-bottom: 6px;
}

.input {
  width: 100%;
  padding: 8px 14px;
  border: 0.5px solid var(--pms-border-2);
  border-radius: var(--radius-md);
  font-family: var(--font);
  font-size: 13px;
  background: #fff;
  color: var(--pms-ink);
}
.sa-textarea { min-height: 70px; resize: vertical; }

.sa-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: 100px;
  font-size: 11px;
  font-weight: 500;
}
.sa-dot { width: 5px; height: 5px; border-radius: 50%; }
.sa-badge--active     { background: var(--pms-green-light); color: var(--pms-green); }
.sa-badge--active .sa-dot     { background: var(--pms-green); }
.sa-badge--trial      { background: var(--pms-gold-light); color: var(--pms-gold-dark); }
.sa-badge--trial .sa-dot      { background: var(--pms-gold); }
.sa-badge--suspended  { background: var(--pms-red-light); color: var(--pms-red); }
.sa-badge--suspended .sa-dot  { background: var(--pms-red); }
.sa-badge--churned    { background: rgba(26,23,20,0.08); color: var(--pms-ink-3); }
.sa-badge--churned .sa-dot    { background: var(--pms-ink-3); }

.table-wrap {
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-md);
  overflow: hidden;
  background: #fff;
}
table { width: 100%; border-collapse: collapse; }
thead tr { background: var(--pms-sand); }
th {
  font-size: 11px;
  font-weight: 500;
  color: var(--pms-ink-3);
  text-align: left;
  padding: 10px 14px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
td {
  font-size: 13px;
  color: var(--pms-ink-2);
  padding: 10px 14px;
  border-top: 0.5px solid var(--pms-border);
}
</style>
