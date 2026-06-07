<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { superadminService } from '@/services/superadmin.service'
import type { TenantDetail, TenantStatus } from '@/types/superadmin'
import { formatCurrency } from '@/utils/currency'

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
const flashMessage         = ref<string | null>(null)

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

function flash(message: string): void {
  flashMessage.value = message
  window.setTimeout(() => {
    flashMessage.value = null
  }, 4000)
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
    flash('Tenant suspendu. L\'accès est bloqué jusqu\'à réactivation.')
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
    flash('Tenant réactivé. L\'accès est rétabli.')
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
          · {{ tenant.subdomain }}.stayos.sn
        </p>
      </header>

      <!-- Flash -->
      <div v-if="flashMessage" class="sa-flash">
        <i class="ti ti-circle-check"></i> {{ flashMessage }}
      </div>

      <!-- Grille principale -->
      <div class="sa-grid">

        <!-- Informations -->
        <section class="card">
          <h2 class="sa-section-title">Informations</h2>
          <dl class="sa-kv">
            <dt>Slug</dt>           <dd><span class="t-mono">{{ tenant.slug }}</span></dd>
            <dt>Subdomain</dt>      <dd>{{ tenant.subdomain }}</dd>
            <dt>Pays</dt>           <dd>{{ tenant.country }}</dd>
            <dt>Devise</dt>         <dd>{{ tenant.currency }}</dd>
            <dt>Créé le</dt>        <dd>{{ formatDateTime(tenant.createdAt) }}</dd>
          </dl>
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

.sa-flash {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--pms-green-light);
  color: var(--pms-green);
  font-size: 13px;
  padding: 10px 14px;
  border-radius: var(--radius-md);
  margin-bottom: 1rem;
}
.sa-flash i { font-size: 16px; }

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
