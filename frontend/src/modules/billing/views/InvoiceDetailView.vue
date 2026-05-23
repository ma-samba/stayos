<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { invoiceService } from '@/services/invoice.service'
import type { Invoice, InvoiceStatus } from '@/types/entities'
import { formatCurrency } from '@/utils/currency'
import api from '@/services/api.service'
import type { ApiSuccess } from '@/types/entities'

const route  = useRoute()
const router = useRouter()

// ── State ──

const invoice  = ref<Invoice | null>(null)
const loading  = ref(true)
const error    = ref<string | null>(null)
const actionLoading = ref(false)
const successMsg    = ref<string | null>(null)
const errorMsg      = ref<string | null>(null)

// ── Modales ──

const showPaymentModal  = ref(false)
const showCheckoutModal = ref(false)

// ── Paiement manuel ──

const paymentForm = ref({
  method: 'cash',
  amountXof: '',
  reference: '',
  notes: '',
})

const manualMethods = [
  { value: 'cash',          label: 'Espèces' },
  { value: 'bank_transfer', label: 'Virement bancaire' },
  { value: 'card',          label: 'Carte bancaire' },
]

// ── Checkout en ligne ──

const checkoutChannels = ref<string[]>([])

const availableChannels = [
  { value: 'wave-senegal',         label: 'Wave',          icon: 'ti-device-mobile' },
  { value: 'orange-money-senegal', label: 'Orange Money',  icon: 'ti-device-mobile' },
  { value: 'card',                 label: 'Carte bancaire', icon: 'ti-credit-card' },
]

// ── Fetch ──

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get<ApiSuccess<Invoice>>(`/invoices/${route.params.id}`)
    invoice.value = data.data
  } catch {
    error.value = 'Facture introuvable'
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

const methodLabels: Record<string, string> = {
  cash:          'Espèces',
  wave:          'Wave',
  orange_money:  'Orange Money',
  card:          'Carte bancaire',
  bank_transfer: 'Virement',
  mobile_money:  'Paiement en ligne',
  ota:           'OTA',
}

function formatDate(s: string): string {
  return new Date(s).toLocaleDateString('fr-SN', {
    day: '2-digit', month: 'long', year: 'numeric',
  })
}

function formatShortDate(s: string | null | undefined): string {
  if (!s) return '—'
  const d = new Date(s)
  if (isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('fr-SN', {
    day: '2-digit', month: 'short', year: 'numeric',
  })
}

function goBack(): void {
  if (window.history.length > 1) {
    router.back()
  } else {
    router.push('/invoices')
  }
}

const canPay = computed(() => {
  if (!invoice.value) return false
  return invoice.value.status === 'issued' || invoice.value.status === 'partial'
})

const canAct = computed(() => {
  if (!invoice.value) return false
  return invoice.value.status !== 'cancelled'
})

// ── Actions ──

function flash(msg: string): void {
  successMsg.value = msg
  setTimeout(() => { successMsg.value = null }, 4000)
}

function flashError(msg: string): void {
  errorMsg.value = msg
  setTimeout(() => { errorMsg.value = null }, 5000)
}

async function handleIssue(): Promise<void> {
  if (!invoice.value) return
  actionLoading.value = true
  try {
    await invoiceService.issue(invoice.value.id)
    flash('Facture émise')
    await load()
  } catch (e: any) {
    flashError(e.response?.data?.error ?? 'Erreur lors de l\'émission')
  } finally {
    actionLoading.value = false
  }
}

async function handleDownloadPdf(): Promise<void> {
  if (!invoice.value) return
  actionLoading.value = true
  try {
    await invoiceService.downloadPdf(invoice.value.id)
  } catch {
    flashError('Erreur lors du téléchargement du PDF')
  } finally {
    actionLoading.value = false
  }
}

async function handleSendEmail(): Promise<void> {
  if (!invoice.value) return
  actionLoading.value = true
  try {
    const result = await invoiceService.send(invoice.value.id)
    if (result.sent) {
      flash(`Facture envoyée à ${result.to}`)
    } else {
      flashError('Erreur lors de l\'envoi')
    }
  } catch (e: any) {
    flashError(e.response?.data?.error ?? 'Erreur lors de l\'envoi')
  } finally {
    actionLoading.value = false
  }
}

// ── Paiement manuel ──

function openPaymentModal(): void {
  if (!invoice.value) return
  paymentForm.value = {
    method: 'cash',
    amountXof: invoice.value.balanceXof,
    reference: '',
    notes: '',
  }
  showPaymentModal.value = true
}

async function submitPayment(): Promise<void> {
  if (!invoice.value) return
  actionLoading.value = true
  try {
    await invoiceService.recordPayment(invoice.value.id, {
      method: paymentForm.value.method,
      amountXof: paymentForm.value.amountXof,
      reference: paymentForm.value.reference || undefined,
      notes: paymentForm.value.notes || undefined,
    })
    showPaymentModal.value = false
    flash('Paiement enregistré')
    await load()
  } catch (e: any) {
    flashError(e.response?.data?.error ?? 'Erreur lors de l\'enregistrement')
  } finally {
    actionLoading.value = false
  }
}

// ── Checkout en ligne ──

function openCheckoutModal(): void {
  checkoutChannels.value = []
  showCheckoutModal.value = true
}

function toggleChannel(ch: string): void {
  const idx = checkoutChannels.value.indexOf(ch)
  if (idx >= 0) {
    checkoutChannels.value.splice(idx, 1)
  } else {
    checkoutChannels.value.push(ch)
  }
}

async function submitCheckout(): Promise<void> {
  if (!invoice.value) return
  actionLoading.value = true
  try {
    const result = await invoiceService.checkout(
      invoice.value.id,
      'paydunya',
      checkoutChannels.value,
    )
    showCheckoutModal.value = false
    // Rediriger vers la page de paiement Paydunya
    window.location.href = result.checkoutUrl
  } catch (e: any) {
    flashError(e.response?.data?.error ?? 'Erreur lors de la création du paiement')
    actionLoading.value = false
  }
}

// ── Gestion retour Paydunya ──

const paymentReturn = ref<'success' | 'cancelled' | null>(null)

onMounted(async () => {
  const q = route.query.payment as string | undefined
  if (q === 'success') paymentReturn.value = 'success'
  else if (q === 'cancelled') paymentReturn.value = 'cancelled'

  await load()
})
</script>

<template>
  <div style="padding:1.5rem; max-width:900px; margin:0 auto;">
    <button class="btn btn-ghost btn-sm" style="margin-bottom:1rem;" @click="goBack()">
      <i class="ti ti-arrow-left"></i> Retour
    </button>

    <!-- Chargement -->
    <div v-if="loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>

    <!-- Erreur -->
    <div v-else-if="error" class="empty-state">
      <i class="ti ti-alert-circle"></i>
      <div>{{ error }}</div>
      <button class="btn btn-secondary btn-sm" @click="goBack()">Retour</button>
    </div>

    <div v-else-if="invoice">

      <!-- ── Message retour Paydunya ── -->
      <div
        v-if="paymentReturn === 'success'"
        style="padding:12px 16px; border-radius:var(--radius-md); margin-bottom:1rem; background:var(--pms-green-light); color:var(--pms-green); display:flex; align-items:center; gap:8px;"
      >
        <i class="ti ti-circle-check"></i> Paiement reçu. Le statut sera mis à jour sous peu.
      </div>
      <div
        v-if="paymentReturn === 'cancelled'"
        style="padding:12px 16px; border-radius:var(--radius-md); margin-bottom:1rem; background:var(--pms-gold-light); color:var(--pms-gold-dark); display:flex; align-items:center; gap:8px;"
      >
        <i class="ti ti-alert-triangle"></i> Paiement annulé par le client.
      </div>

      <!-- ── Toasts ── -->
      <div
        v-if="successMsg"
        style="padding:12px 16px; border-radius:var(--radius-md); margin-bottom:1rem; background:var(--pms-green-light); color:var(--pms-green); display:flex; align-items:center; gap:8px;"
      >
        <i class="ti ti-circle-check"></i> {{ successMsg }}
      </div>
      <div
        v-if="errorMsg"
        style="padding:12px 16px; border-radius:var(--radius-md); margin-bottom:1rem; background:var(--pms-red-light); color:var(--pms-red); display:flex; align-items:center; gap:8px;"
      >
        <i class="ti ti-alert-circle"></i> {{ errorMsg }}
      </div>

      <!-- ── En-tête ── -->
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem;">
        <div>
          <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
            <span class="t-mono">{{ invoice.number }}</span>
          </h1>
          <p class="t-muted">Détail de la facture</p>
        </div>
        <span :class="['badge', statusBadgeClass[invoice.status]]">
          <span class="badge-dot"></span>{{ statusLabels[invoice.status] }}
        </span>
      </div>

      <!-- ── Barre d'actions ── -->
      <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.5rem;">
        <!-- Draft → Émettre -->
        <button
          v-if="invoice.status === 'draft'"
          class="btn btn-primary btn-sm"
          :disabled="actionLoading"
          @click="handleIssue()"
        >
          <i class="ti ti-send"></i> Émettre la facture
        </button>

        <!-- Paiement manuel -->
        <button
          v-if="canPay"
          class="btn btn-secondary btn-sm"
          @click="openPaymentModal()"
        >
          <i class="ti ti-cash"></i> Enregistrer un paiement
        </button>

        <!-- Payer en ligne -->
        <button
          v-if="canPay"
          class="btn btn-sm"
          style="background:var(--pms-teal); color:#fff;"
          @click="openCheckoutModal()"
        >
          <i class="ti ti-device-mobile"></i> Payer en ligne
        </button>

        <!-- PDF -->
        <button
          v-if="canAct"
          class="btn btn-ghost btn-sm"
          :disabled="actionLoading"
          @click="handleDownloadPdf()"
        >
          <i class="ti ti-download"></i> PDF
        </button>

        <!-- Email -->
        <button
          v-if="canAct"
          class="btn btn-ghost btn-sm"
          :disabled="actionLoading"
          @click="handleSendEmail()"
        >
          <i class="ti ti-mail"></i> Envoyer par email
        </button>
      </div>

      <!-- ── Bloc client ── -->
      <div v-if="invoice.reservation?.guest" class="card" style="padding:1.25rem; margin-bottom:1rem;">
        <div class="section-label">Client</div>
        <div style="display:flex; align-items:center; gap:12px;">
          <div class="avatar avatar-md avatar-teal">
            {{ ((invoice.reservation.guest.firstName?.[0] ?? '') + (invoice.reservation.guest.lastName?.[0] ?? '')).toUpperCase() }}
          </div>
          <div>
            <div style="font-weight:500; font-size:15px;">
              {{ invoice.reservation.guest.firstName }} {{ invoice.reservation.guest.lastName }}
            </div>
            <div class="t-muted" style="font-size:13px;">
              {{ invoice.reservation.guest.email || '—' }} · {{ invoice.reservation.guest.phone || '—' }}
            </div>
          </div>
        </div>
      </div>

      <!-- ── Bloc séjour ── -->
      <div v-if="invoice.reservation" class="card" style="padding:1.25rem; margin-bottom:1rem;">
        <div class="section-label">Réservation</div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <div class="t-muted" style="font-size:12px;">Référence</div>
            <div class="t-mono">{{ invoice.reservation.confirmationNumber ?? '—' }}</div>
          </div>
          <div v-if="invoice.reservation.room">
            <div class="t-muted" style="font-size:12px;">Chambre</div>
            <div>
              <span class="t-mono">{{ invoice.reservation.room.number }}</span>
              <span v-if="invoice.reservation.room.type" class="t-muted" style="margin-left:6px;">{{ invoice.reservation.room.type.name }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Lignes de facture ── -->
      <div v-if="invoice.lines && invoice.lines.length > 0" class="card" style="padding:0; margin-bottom:1rem; overflow:hidden;">
        <div style="padding:1.25rem 1.25rem 0.75rem;">
          <div class="section-label">Lignes de facture</div>
        </div>
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr>
              <th style="text-align:left; padding:8px 1.25rem; font-size:11px; font-weight:500; color:var(--pms-ink-3); letter-spacing:0.04em; background:var(--pms-sand);">Description</th>
              <th style="text-align:right; padding:8px 1.25rem; font-size:11px; font-weight:500; color:var(--pms-ink-3); background:var(--pms-sand);">Qté</th>
              <th style="text-align:right; padding:8px 1.25rem; font-size:11px; font-weight:500; color:var(--pms-ink-3); background:var(--pms-sand);">PU</th>
              <th style="text-align:right; padding:8px 1.25rem; font-size:11px; font-weight:500; color:var(--pms-ink-3); background:var(--pms-sand);">Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in invoice.lines" :key="line.id">
              <td style="padding:10px 1.25rem; font-size:13px; border-top:0.5px solid var(--pms-border);">{{ line.label }}</td>
              <td style="padding:10px 1.25rem; font-size:13px; text-align:right; border-top:0.5px solid var(--pms-border);">{{ line.quantity }}</td>
              <td style="padding:10px 1.25rem; font-size:13px; text-align:right; border-top:0.5px solid var(--pms-border);">{{ formatCurrency(line.unitPriceXof) }}</td>
              <td style="padding:10px 1.25rem; font-size:13px; text-align:right; font-weight:500; border-top:0.5px solid var(--pms-border);">{{ formatCurrency(line.totalXof) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ── Bloc totaux ── -->
      <div class="card-sand" style="padding:1.25rem; margin-bottom:1rem;">
        <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
          <span class="t-muted">Sous-total HT</span>
          <span>{{ formatCurrency(invoice.subtotalXof) }}</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
          <span class="t-muted">TVA ({{ invoice.taxRate }}%)</span>
          <span>{{ formatCurrency(invoice.taxXof) }}</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:10px; padding-top:8px; border-top:0.5px solid var(--pms-border-2); font-size:16px; font-weight:500; color:var(--pms-ink);">
          <span>Total TTC</span>
          <span>{{ formatCurrency(invoice.totalXof) }}</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
          <span class="t-muted">Payé</span>
          <span style="color:var(--pms-green);">{{ formatCurrency(invoice.paidXof) }}</span>
        </div>
        <div style="display:flex; justify-content:space-between; font-weight:500;">
          <span>Solde restant</span>
          <span :style="{ color: Number(invoice.balanceXof) > 0 ? 'var(--pms-red)' : 'var(--pms-green)' }">
            {{ formatCurrency(invoice.balanceXof) }}
          </span>
        </div>
      </div>

      <!-- ── Paiements reçus ── -->
      <div class="card" style="padding:1.25rem; margin-bottom:1rem;">
        <div class="section-label">Paiements reçus</div>

        <div v-if="!invoice.payments || invoice.payments.length === 0" class="t-muted" style="font-size:13px;">
          Aucun paiement enregistré.
        </div>

        <div v-else style="display:flex; flex-direction:column; gap:10px;">
          <div
            v-for="p in invoice.payments"
            :key="p.id"
            style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; background:var(--pms-sand); border-radius:var(--radius-md);"
          >
            <div style="display:flex; align-items:center; gap:10px;">
              <i class="ti ti-cash" style="font-size:18px; color:var(--pms-ink-3);" aria-hidden="true"></i>
              <div>
                <div style="font-size:13px; font-weight:500;">{{ methodLabels[p.method] || p.method }}</div>
                <div class="t-muted" style="font-size:12px;">
                  {{ formatShortDate(p.paidAt || p.processedAt) }}
                  <template v-if="p.reference"> · {{ p.reference }}</template>
                </div>
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
              <span style="font-weight:500; font-size:14px;">{{ formatCurrency(p.amountXof) }}</span>
              <span
                :class="['badge', p.status === 'paid' ? 'badge-paid' : p.status === 'pending' ? 'badge-partial' : p.status === 'failed' ? 'badge-cancelled' : 'badge-draft']"
                style="font-size:10px;"
              >
                <span class="badge-dot"></span>
                {{ p.status === 'paid' ? 'Payé' : p.status === 'pending' ? 'En attente' : p.status === 'failed' ? 'Échoué' : p.status }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Notes ── -->
      <div v-if="invoice.notes" class="card" style="padding:1.25rem; margin-bottom:1rem;">
        <div class="section-label">Notes</div>
        <div style="font-size:14px; color:var(--pms-ink-2);">{{ invoice.notes }}</div>
      </div>

      <!-- ── Dates ── -->
      <div class="card" style="padding:1.25rem;">
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
          <div>
            <div class="t-muted" style="font-size:12px;">Créée le</div>
            <div style="font-size:13px;">{{ formatDate(invoice.createdAt) }}</div>
          </div>
          <div v-if="invoice.issuedAt">
            <div class="t-muted" style="font-size:12px;">Émise le</div>
            <div style="font-size:13px;">{{ formatDate(invoice.issuedAt) }}</div>
          </div>
          <div v-if="invoice.dueAt">
            <div class="t-muted" style="font-size:12px;">Échéance</div>
            <div style="font-size:13px;">{{ formatDate(invoice.dueAt) }}</div>
          </div>
        </div>
      </div>

    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!--  MODALE : Paiement manuel                              -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div
      v-if="showPaymentModal"
      style="position:fixed; inset:0; background:rgba(26,23,20,0.4); display:flex; align-items:center; justify-content:center; z-index:100;"
      @click.self="showPaymentModal = false"
    >
      <div style="background:#fff; border-radius:var(--radius-xl); padding:1.5rem; width:440px; max-width:90vw;">
        <h3 style="font-size:16px; font-weight:500; color:var(--pms-ink); margin-bottom:16px;">
          Enregistrer un paiement
        </h3>

        <div class="input-wrap" style="margin-bottom:12px;">
          <label class="input-label">Moyen de paiement</label>
          <select v-model="paymentForm.method" class="input" style="width:100%;">
            <option v-for="m in manualMethods" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>

        <div class="input-wrap" style="margin-bottom:12px;">
          <label class="input-label">Montant (FCFA)</label>
          <input v-model="paymentForm.amountXof" class="input" type="text" style="width:100%;" />
          <span class="input-hint">Solde restant : {{ invoice ? formatCurrency(invoice.balanceXof) : '' }}</span>
        </div>

        <div class="input-wrap" style="margin-bottom:12px;">
          <label class="input-label">Référence <span class="t-muted">(optionnel)</span></label>
          <input v-model="paymentForm.reference" class="input" type="text" placeholder="N° reçu, transaction..." style="width:100%;" />
        </div>

        <div class="input-wrap" style="margin-bottom:16px;">
          <label class="input-label">Notes <span class="t-muted">(optionnel)</span></label>
          <input v-model="paymentForm.notes" class="input" type="text" style="width:100%;" />
        </div>

        <div style="display:flex; gap:8px; justify-content:flex-end;">
          <button class="btn btn-ghost btn-sm" @click="showPaymentModal = false">Annuler</button>
          <button class="btn btn-primary btn-sm" :disabled="actionLoading" @click="submitPayment()">
            Enregistrer
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!--  MODALE : Paiement en ligne (Paydunya)                 -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div
      v-if="showCheckoutModal"
      style="position:fixed; inset:0; background:rgba(26,23,20,0.4); display:flex; align-items:center; justify-content:center; z-index:100;"
      @click.self="showCheckoutModal = false"
    >
      <div style="background:#fff; border-radius:var(--radius-xl); padding:1.5rem; width:440px; max-width:90vw;">
        <h3 style="font-size:16px; font-weight:500; color:var(--pms-ink); margin-bottom:6px;">
          Payer en ligne
        </h3>
        <p class="t-muted" style="font-size:13px; margin-bottom:16px;">
          Montant : <strong>{{ invoice ? formatCurrency(invoice.balanceXof) : '' }}</strong>
        </p>

        <div style="margin-bottom:16px;">
          <label class="input-label" style="margin-bottom:8px; display:block;">Canaux de paiement</label>
          <div style="display:flex; flex-direction:column; gap:8px;">
            <div
              v-for="ch in availableChannels"
              :key="ch.value"
              :class="['channel-option', checkoutChannels.includes(ch.value) ? 'channel-selected' : '']"
              @click="toggleChannel(ch.value)"
            >
              <div class="check" :class="{ on: checkoutChannels.includes(ch.value) }">
                <i v-if="checkoutChannels.includes(ch.value)" class="ti ti-check" style="font-size:12px;"></i>
              </div>
              <i :class="['ti', ch.icon]" style="font-size:18px;" aria-hidden="true"></i>
              <span style="font-size:13px; font-weight:500;">{{ ch.label }}</span>
            </div>
          </div>
          <span class="input-hint" style="margin-top:6px; display:block;">
            Laissez vide pour proposer tous les canaux au client.
          </span>
        </div>

        <div style="display:flex; gap:8px; justify-content:flex-end;">
          <button class="btn btn-ghost btn-sm" @click="showCheckoutModal = false">Annuler</button>
          <button
            class="btn btn-sm"
            style="background:var(--pms-teal); color:#fff;"
            :disabled="actionLoading"
            @click="submitCheckout()"
          >
            <i class="ti ti-external-link"></i> Continuer vers le paiement
          </button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.section-label {
  font-size: 11px;
  font-weight: 500;
  color: var(--pms-ink-3);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin-bottom: 10px;
}

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

.channel-option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: 0.5px solid var(--pms-border-2);
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: all 0.15s;
}
.channel-option:hover {
  background: var(--pms-sand);
}
.channel-selected {
  border-color: var(--pms-teal);
  background: var(--pms-teal-light);
}

.check {
  width: 18px; height: 18px;
  border-radius: 4px;
  border: 1.5px solid var(--pms-border-2);
  display: flex; align-items: center; justify-content: center;
  transition: all 0.15s;
  flex-shrink: 0;
}
.check.on {
  background: var(--pms-teal);
  border-color: var(--pms-teal);
  color: #fff;
}
</style>
