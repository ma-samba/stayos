<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { subscriptionService } from '@/services/subscription.service'
import { formatCurrency } from '@/utils/currency'
import type { SaasInvoice, SaasInvoiceStatus } from '@/types/entities'

const router = useRouter()

const invoices = ref<SaasInvoice[]>([])
const loading  = ref(true)
const error    = ref<string | null>(null)

async function fetchInvoices(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    invoices.value = await subscriptionService.getInvoices()
  } catch {
    error.value = "Impossible de charger l'historique"
  } finally {
    loading.value = false
  }
}

// ── Helpers ────────────────────────────────────────────────

function formatDate(dateStr: string | null, opts: Intl.DateTimeFormatOptions = {
  day: '2-digit', month: 'short', year: 'numeric',
}): string {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('fr-FR', opts)
}

function formatPeriod(start: string, end: string): string {
  return `${formatDate(start)} → ${formatDate(end)}`
}

const statusBadgeClass: Record<SaasInvoiceStatus, string> = {
  draft:     'badge-draft',
  pending:   'badge-issued',
  paid:      'badge-paid',
  failed:    'badge-cancelled',
  cancelled: 'badge-cancelled',
}

const statusLabels: Record<SaasInvoiceStatus, string> = {
  draft:     'Brouillon',
  pending:   'En attente',
  paid:      'Réglée',
  failed:    'Échouée',
  cancelled: 'Annulée',
}

// ── Stats ──────────────────────────────────────────────────

const paidCount = computed(() => invoices.value.filter(i => i.status === 'paid').length)
const pendingCount = computed(() => invoices.value.filter(i => i.status === 'pending').length)
const totalPaid = computed(() => {
  return invoices.value
    .filter(i => i.status === 'paid')
    .reduce((sum, i) => sum + Number(i.amountXof), 0)
})

// ── Actions ────────────────────────────────────────────────

function pay(invoice: SaasInvoice): void {
  if (!invoice.checkoutUrl) return
  window.location.href = invoice.checkoutUrl
}

function backToSubscription(): void {
  router.push('/subscription')
}

onMounted(fetchInvoices)
</script>

<template>
  <div style="padding:1.5rem; max-width:1400px; margin:0 auto;">

    <!-- Retour -->
    <button class="btn btn-ghost btn-sm" style="margin-bottom:0.75rem;" @click="backToSubscription()">
      <i class="ti ti-arrow-left" aria-hidden="true"></i>
      Mon abonnement
    </button>

    <!-- En-tête -->
    <div style="margin-bottom:1.5rem;">
      <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
        Historique des factures
      </h1>
      <p class="t-muted">Vos abonnements StayOS</p>
    </div>

    <!-- Stat cards -->
    <div
      style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));
             gap:12px; margin-bottom:1.5rem;"
    >
      <div class="stat-card">
        <div class="stat-label">Réglées</div>
        <div class="stat-value" style="color:var(--pms-green);">{{ paidCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">À régler</div>
        <div
          class="stat-value"
          :style="{ color: pendingCount > 0 ? 'var(--pms-gold-dark)' : 'var(--pms-ink-3)' }"
        >
          {{ pendingCount }}
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total payé</div>
        <div class="stat-value" style="color:var(--pms-ink);">
          {{ formatCurrency(totalPaid) }}
        </div>
      </div>
    </div>

    <!-- Chargement / erreur -->
    <div v-if="loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>
    <div v-else-if="error" class="empty-state">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <div>{{ error }}</div>
      <button class="btn btn-secondary btn-sm" @click="fetchInvoices()">Réessayer</button>
    </div>

    <!-- État vide -->
    <div v-else-if="invoices.length === 0" class="empty-state">
      <i class="ti ti-receipt" aria-hidden="true"></i>
      <div>Aucune facture pour le moment</div>
    </div>

    <!-- Tableau -->
    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Numéro</th>
            <th>Période</th>
            <th>Plan</th>
            <th>Montant</th>
            <th>Statut</th>
            <th>Échéance</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="inv in invoices" :key="inv.id">
            <td><span class="t-mono">{{ inv.number }}</span></td>
            <td class="t-muted">{{ formatPeriod(inv.periodStart, inv.periodEnd) }}</td>
            <td>{{ inv.planName }}</td>
            <td style="font-weight:500;">{{ formatCurrency(inv.amountXof) }}</td>
            <td>
              <span :class="['badge', statusBadgeClass[inv.status]]">
                <span class="badge-dot"></span>{{ statusLabels[inv.status] }}
              </span>
            </td>
            <td class="t-muted">{{ formatDate(inv.dueAt) }}</td>
            <td>
              <button
                v-if="inv.status === 'pending' && inv.checkoutUrl"
                class="btn btn-primary btn-sm"
                @click="pay(inv)"
              >
                <i class="ti ti-credit-card" aria-hidden="true"></i>
                Régler
              </button>
              <span v-else-if="inv.status === 'paid'" class="t-muted">
                <i class="ti ti-circle-check" aria-hidden="true" style="color:var(--pms-green);"></i>
                Réglée le {{ formatDate(inv.paidAt, { day: '2-digit', month: 'short' }) }}
              </span>
              <span v-else-if="inv.status === 'failed'" class="t-muted">
                Contactez le support
              </span>
              <span v-else class="t-muted">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

  </div>
</template>

<style scoped>
.badge-draft     { background: var(--pms-sand-2); color: var(--pms-ink-3); }
.badge-draft .badge-dot { background: var(--pms-ink-3); }
.badge-issued    { background: var(--pms-gold-light); color: var(--pms-gold-dark); }
.badge-issued .badge-dot { background: var(--pms-gold); }
.badge-paid      { background: var(--pms-green-light); color: var(--pms-green); }
.badge-paid .badge-dot { background: var(--pms-green); }
.badge-cancelled { background: var(--pms-red-light); color: var(--pms-red); }
.badge-cancelled .badge-dot { background: var(--pms-red); }
</style>
