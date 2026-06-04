<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { subscriptionService } from '@/services/subscription.service'
import { useNotificationsStore } from '@/stores/notifications.store'
import { featureLabel } from '@/modules/subscription/feature-labels'
import { formatCurrency } from '@/utils/currency'
import type { Subscription, SubscriptionStatus } from '@/types/entities'

const router = useRouter()
const notif  = useNotificationsStore()

// ── State ──────────────────────────────────────────────────

const subscription  = ref<Subscription | null>(null)
const loading       = ref(true)
const error         = ref<string | null>(null)
const confirmingCancel = ref(false)
const cancelling    = ref(false)

// ── Fetch ──────────────────────────────────────────────────

async function fetchSubscription(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    subscription.value = await subscriptionService.getCurrent()
  } catch {
    error.value = "Impossible de charger l'abonnement"
  } finally {
    loading.value = false
  }
}

async function confirmCancel(): Promise<void> {
  cancelling.value = true
  try {
    await subscriptionService.cancel()
    notif.pushUiToast(
      'success',
      'Abonnement annulé',
      "L'accès reste actif jusqu'à la fin de la période en cours.",
    )
    confirmingCancel.value = false
    await fetchSubscription()
  } catch {
    notif.pushUiToast('alert', "L'annulation a échoué, réessayez.")
  } finally {
    cancelling.value = false
  }
}

// ── Helpers ────────────────────────────────────────────────

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('fr-FR', {
    day: '2-digit', month: 'long', year: 'numeric',
  })
}

function daysUntil(dateStr: string | null): number | null {
  if (!dateStr) return null
  const target = new Date(dateStr).getTime()
  const now    = Date.now()
  return Math.max(0, Math.ceil((target - now) / (1000 * 60 * 60 * 24)))
}

interface UsageInfo {
  label: string
  used: number
  limit: number | null
  ratio: number
  warn: boolean
}

const roomsUsage = computed<UsageInfo | null>(() => {
  if (!subscription.value) return null
  const used  = subscription.value.usage.rooms
  const limit = subscription.value.plan.maxRooms
  const ratio = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0
  return { label: 'Chambres', used, limit, ratio, warn: !!limit && ratio >= 80 }
})

const usersUsage = computed<UsageInfo | null>(() => {
  if (!subscription.value) return null
  const used  = subscription.value.usage.users
  const limit = subscription.value.plan.maxUsers
  const ratio = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0
  return { label: 'Utilisateurs', used, limit, ratio, warn: !!limit && ratio >= 80 }
})

const accessEndDate = computed(() => {
  if (!subscription.value) return null
  return subscription.value.currentPeriodEnd ?? subscription.value.trialEndsAt
})

const trialDaysLeft = computed(() => daysUntil(subscription.value?.trialEndsAt ?? null))

interface StatusBanner {
  variant: 'trial' | 'active' | 'cancelled' | 'suspended'
  icon: string
  message: string
  ctaLabel?: string
  ctaTarget?: string
}

const statusBanner = computed<StatusBanner | null>(() => {
  if (!subscription.value) return null

  const s: SubscriptionStatus = subscription.value.status
  switch (s) {
    case 'trial': {
      const left = trialDaysLeft.value ?? 0
      const date = formatDate(subscription.value.trialEndsAt)
      return {
        variant: 'trial',
        icon: 'ti-info-circle',
        message: `Essai gratuit — il vous reste ${left} jour${left > 1 ? 's' : ''}. Choisissez un plan avant le ${date} pour continuer.`,
        ctaLabel: 'Voir les plans',
        ctaTarget: '/subscription/pricing',
      }
    }
    case 'active':
      return {
        variant: 'active',
        icon: 'ti-circle-check',
        message: `Abonnement actif — renouvellement le ${formatDate(subscription.value.currentPeriodEnd)}.`,
      }
    case 'cancelled':
      return {
        variant: 'cancelled',
        icon: 'ti-alert-triangle',
        message: `Abonnement annulé — accès jusqu'au ${formatDate(accessEndDate.value)}.`,
      }
    case 'suspended':
      return {
        variant: 'suspended',
        icon: 'ti-circle-x',
        message: 'Accès suspendu. Contactez le support pour réactiver votre abonnement.',
        ctaLabel: 'Contacter le support',
        ctaTarget: 'mailto:support@stayos.sn',
      }
  }
})

function gotoPricing(): void {
  router.push('/subscription/pricing')
}

function gotoInvoices(): void {
  router.push('/subscription/invoices')
}

function onBannerCta(target: string): void {
  if (target.startsWith('mailto:')) {
    window.location.href = target
  } else {
    router.push(target)
  }
}

const canCancel = computed(() => {
  const s = subscription.value?.status
  return s === 'active' || s === 'trial'
})

onMounted(fetchSubscription)
</script>

<template>
  <div style="padding:1.5rem; max-width:1200px; margin:0 auto;">

    <!-- ── En-tête ── -->
    <div style="margin-bottom:1.5rem;">
      <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
        Abonnement
      </h1>
      <p class="t-muted">Plan actuel et utilisation</p>
    </div>

    <!-- ── Chargement ── -->
    <div v-if="loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>

    <!-- ── Erreur réseau ── -->
    <div v-else-if="error" class="empty-state">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <div>{{ error }}</div>
      <button class="btn btn-secondary btn-sm" @click="fetchSubscription()">Réessayer</button>
    </div>

    <!-- ── Aucun abonnement ── -->
    <div v-else-if="!subscription" class="empty-state">
      <i class="ti ti-crown" aria-hidden="true"></i>
      <div>Aucun abonnement actif</div>
      <button class="btn btn-primary btn-sm" @click="gotoPricing()">Choisir un plan</button>
    </div>

    <template v-else>

      <!-- ── Bandeau d'état ── -->
      <div v-if="statusBanner" :class="['status-banner', `banner-${statusBanner.variant}`]">
        <i :class="['ti', statusBanner.icon]" aria-hidden="true" class="banner-icon"></i>
        <div class="banner-message">{{ statusBanner.message }}</div>
        <button
          v-if="statusBanner.ctaLabel && statusBanner.ctaTarget"
          class="btn btn-secondary btn-sm"
          @click="onBannerCta(statusBanner.ctaTarget)"
        >
          {{ statusBanner.ctaLabel }}
        </button>
      </div>

      <!-- ── Carte Plan actuel ── -->
      <div class="card" style="margin-bottom:1.25rem; padding:1.5rem;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap;">
          <div>
            <div class="t-muted" style="text-transform:uppercase; letter-spacing:0.04em; font-weight:500; margin-bottom:6px;">
              Plan actuel
            </div>
            <h2 style="font-size:24px; font-weight:500; color:var(--pms-ink); margin:0 0 8px;">
              {{ subscription.plan.name }}
            </h2>
            <div style="display:flex; align-items:baseline; gap:6px;">
              <span style="font-size:18px; font-weight:500; color:var(--pms-ink);">
                {{ formatCurrency(subscription.plan.priceXof) }}
              </span>
              <span class="t-muted">/ mois HT</span>
            </div>
          </div>

          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button class="btn btn-secondary btn-sm" @click="gotoPricing()">
              <i class="ti ti-arrow-up-right" aria-hidden="true"></i>
              Changer de plan
            </button>
            <button
              v-if="canCancel && !confirmingCancel"
              class="btn btn-ghost btn-sm"
              @click="confirmingCancel = true"
            >
              Annuler l'abonnement
            </button>
          </div>
        </div>

        <!-- Inline confirm cancel (pattern TaskCard) -->
        <div v-if="confirmingCancel" class="cancel-confirm">
          <div class="cancel-confirm-text">
            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
            Confirmer l'annulation ? L'accès reste ouvert jusqu'au
            <strong>{{ formatDate(accessEndDate) }}</strong>.
          </div>
          <div style="display:flex; gap:6px;">
            <button class="btn btn-ghost btn-sm" :disabled="cancelling" @click="confirmingCancel = false">
              Non
            </button>
            <button class="btn btn-danger btn-sm" :disabled="cancelling" @click="confirmCancel()">
              <span v-if="cancelling" class="spinner" style="width:14px; height:14px; border-width:1.5px;"></span>
              <template v-else>Oui, annuler</template>
            </button>
          </div>
        </div>

        <!-- Features -->
        <div v-if="subscription.plan.features.length > 0" style="margin-top:1.25rem;">
          <div class="t-muted" style="text-transform:uppercase; letter-spacing:0.04em; font-weight:500; margin-bottom:8px;">
            Inclus dans votre plan
          </div>
          <ul class="feature-list">
            <li v-for="f in subscription.plan.features" :key="f">
              <i class="ti ti-circle-check" aria-hidden="true"></i>
              {{ featureLabel(f) }}
            </li>
          </ul>
        </div>
      </div>

      <!-- ── Stat cards Utilisation ── -->
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:12px; margin-bottom:1.5rem;">
        <div v-for="u in [roomsUsage, usersUsage].filter(Boolean) as UsageInfo[]" :key="u.label" class="stat-card">
          <div class="stat-label">{{ u.label }}</div>
          <div style="display:flex; align-items:baseline; gap:6px;">
            <span class="stat-value">{{ u.used }}</span>
            <span class="t-muted">
              / {{ u.limit ?? 'Illimité' }}
            </span>
          </div>

          <div v-if="u.limit" class="usage-bar" :class="{ warn: u.warn }">
            <div class="usage-bar-fill" :style="{ width: u.ratio + '%' }"></div>
          </div>
          <div v-if="u.warn" class="usage-warn">
            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
            Vous approchez de la limite de votre plan.
          </div>
        </div>
      </div>

      <!-- ── Lien historique ── -->
      <div>
        <button class="btn btn-ghost btn-sm" @click="gotoInvoices()">
          <i class="ti ti-receipt" aria-hidden="true"></i>
          Voir l'historique de factures
        </button>
      </div>

    </template>

  </div>
</template>

<style scoped>
.status-banner {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 18px;
  border-radius: var(--radius-md);
  margin-bottom: 1.25rem;
  border: 0.5px solid transparent;
}
.banner-icon { font-size: 20px; flex-shrink: 0; }
.banner-message { flex: 1; font-size: 13px; line-height: 1.4; }

.banner-trial     { background: var(--pms-gold-light);  color: var(--pms-gold-dark); border-color: var(--pms-gold); }
.banner-trial .banner-icon { color: var(--pms-gold-dark); }
.banner-active    { background: var(--pms-green-light); color: var(--pms-green); }
.banner-active .banner-icon { color: var(--pms-green); }
.banner-cancelled { background: var(--pms-gold-light);  color: var(--pms-gold-dark); }
.banner-cancelled .banner-icon { color: var(--pms-gold-dark); }
.banner-suspended { background: var(--pms-red-light);   color: var(--pms-red); border-color: var(--pms-red); }
.banner-suspended .banner-icon { color: var(--pms-red); }

.cancel-confirm {
  margin-top: 1rem;
  padding: 12px 14px;
  background: var(--pms-sand);
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-md);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.cancel-confirm-text {
  font-size: 13px;
  color: var(--pms-ink-2);
}
.cancel-confirm-text i {
  color: var(--pms-gold-dark);
  margin-right: 4px;
}

.feature-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 6px;
}
.feature-list li {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--pms-ink-2);
}
.feature-list i {
  color: var(--pms-green);
  font-size: 16px;
}

.usage-bar {
  margin-top: 12px;
  height: 6px;
  background: var(--pms-sand-2);
  border-radius: 100px;
  overflow: hidden;
}
.usage-bar-fill {
  height: 100%;
  background: var(--pms-teal);
  transition: width 0.3s ease;
}
.usage-bar.warn .usage-bar-fill {
  background: var(--pms-gold);
}
.usage-warn {
  font-size: 11px;
  color: var(--pms-gold-dark);
  margin-top: 6px;
  display: flex;
  align-items: center;
  gap: 4px;
}
</style>
