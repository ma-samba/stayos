<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { invoiceService } from '@/services/invoice.service'
import type { Invoice, InvoiceStatus } from '@/types/entities'
import { formatCurrency } from '@/utils/currency'

const router = useRouter()

// ── State ──

const invoices = ref<Invoice[]>([])
const loading  = ref(true)
const error    = ref<string | null>(null)

// ── Filtres ──

const activeFilter = ref<InvoiceStatus | 'all'>('all')

const statusFilters: { value: typeof activeFilter.value; label: string }[] = [
  { value: 'all',       label: 'Toutes' },
  { value: 'draft',     label: 'Brouillon' },
  { value: 'issued',    label: 'Émises' },
  { value: 'partial',   label: 'Partielles' },
  { value: 'paid',      label: 'Payées' },
  { value: 'cancelled', label: 'Annulées' },
]

const filteredInvoices = computed(() => {
  if (activeFilter.value === 'all') return invoices.value
  return invoices.value.filter(i => i.status === activeFilter.value)
})

// ── Fetch ──

async function fetchInvoices(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    invoices.value = await invoiceService.list()
  } catch {
    error.value = 'Impossible de charger les factures'
  } finally {
    loading.value = false
  }
}

// ── Helpers ──

const statusBadgeClass: Record<InvoiceStatus, string> = {
  draft:     'badge-draft',
  issued:    'badge-issued',
  partial:   'badge-partial',
  paid:      'badge-paid',
  cancelled: 'badge-cancelled',
}

const statusLabels: Record<InvoiceStatus, string> = {
  draft:     'Brouillon',
  issued:    'Émise',
  partial:   'Partielle',
  paid:      'Payée',
  cancelled: 'Annulée',
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('fr-SN', {
    day: '2-digit', month: 'short', year: 'numeric',
  })
}

function guestName(inv: Invoice): string {
  if (!inv.reservation?.guest) return '—'
  return `${inv.reservation.guest.firstName} ${inv.reservation.guest.lastName}`
}

function guestInitials(inv: Invoice): string {
  if (!inv.reservation?.guest) return '?'
  return (inv.reservation.guest.firstName[0] + inv.reservation.guest.lastName[0]).toUpperCase()
}

function openDetail(id: string): void {
  router.push(`/invoices/${id}`)
}

// ── Stats ──

const draftCount     = computed(() => invoices.value.filter(i => i.status === 'draft').length)
const issuedCount    = computed(() => invoices.value.filter(i => i.status === 'issued').length)
const partialCount   = computed(() => invoices.value.filter(i => i.status === 'partial').length)
const paidCount      = computed(() => invoices.value.filter(i => i.status === 'paid').length)

onMounted(fetchInvoices)
</script>

<template>
  <div style="padding:1.5rem; max-width:1400px; margin:0 auto;">

    <!-- ── En-tête ── -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
      <div>
        <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
          Factures
        </h1>
        <p class="t-muted">Suivi des factures et paiements</p>
      </div>
    </div>

    <!-- ── Stat cards ── -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:1.5rem;">
      <div class="stat-card">
        <div class="stat-label">Brouillon</div>
        <div class="stat-value" style="color:var(--pms-ink-3);">{{ draftCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Émises</div>
        <div class="stat-value" style="color:var(--pms-blue);">{{ issuedCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Partielles</div>
        <div class="stat-value" style="color:var(--pms-gold-dark);">{{ partialCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Payées</div>
        <div class="stat-value" style="color:var(--pms-green);">{{ paidCount }}</div>
      </div>
    </div>

    <!-- ── Filtres par statut ── -->
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.5rem;">
      <button
        v-for="f in statusFilters"
        :key="f.value"
        :class="['btn', 'btn-sm', activeFilter === f.value ? 'btn-primary' : 'btn-secondary']"
        @click="activeFilter = f.value"
      >
        {{ f.label }}
      </button>
    </div>

    <!-- ── Chargement ── -->
    <div v-if="loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>

    <!-- ── Erreur ── -->
    <div v-else-if="error" class="empty-state">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <div>{{ error }}</div>
      <button class="btn btn-secondary btn-sm" @click="fetchInvoices()">Réessayer</button>
    </div>

    <!-- ── Aucun résultat ── -->
    <div v-else-if="filteredInvoices.length === 0" class="empty-state">
      <i class="ti ti-file-invoice" aria-hidden="true"></i>
      <div>Aucune facture</div>
    </div>

    <!-- ── Tableau ── -->
    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Numéro</th>
            <th>Client</th>
            <th>Total</th>
            <th>Payé</th>
            <th>Solde</th>
            <th>Statut</th>
            <th>Date</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="inv in filteredInvoices"
            :key="inv.id"
            style="cursor:pointer;"
            @click="openDetail(inv.id)"
          >
            <td><span class="t-mono">{{ inv.number }}</span></td>
            <td>
              <div style="display:flex; align-items:center; gap:8px;">
                <div class="avatar avatar-sm avatar-teal">{{ guestInitials(inv) }}</div>
                {{ guestName(inv) }}
              </div>
            </td>
            <td style="font-weight:500;">{{ formatCurrency(inv.totalXof) }}</td>
            <td>{{ formatCurrency(inv.paidXof) }}</td>
            <td :style="{ color: Number(inv.balanceXof) > 0 ? 'var(--pms-red)' : 'var(--pms-green)', fontWeight: 500 }">
              {{ formatCurrency(inv.balanceXof) }}
            </td>
            <td>
              <span :class="['badge', statusBadgeClass[inv.status]]">
                <span class="badge-dot"></span>{{ statusLabels[inv.status] }}
              </span>
            </td>
            <td class="t-muted">{{ formatDate(inv.createdAt) }}</td>
            <td>
              <button class="btn btn-ghost btn-icon-sm" title="Détail" @click.stop="openDetail(inv.id)">
                <i class="ti ti-eye" aria-hidden="true"></i>
              </button>
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
.badge-issued    { background: var(--pms-blue-light); color: var(--pms-blue); }
.badge-issued .badge-dot { background: var(--pms-blue); }
.badge-partial   { background: var(--pms-gold-light); color: var(--pms-gold-dark); }
.badge-partial .badge-dot { background: var(--pms-gold); }
.badge-paid      { background: var(--pms-green-light); color: var(--pms-green); }
.badge-paid .badge-dot { background: var(--pms-green); }
.badge-cancelled { background: var(--pms-red-light); color: var(--pms-red); }
.badge-cancelled .badge-dot { background: var(--pms-red); }
</style>
