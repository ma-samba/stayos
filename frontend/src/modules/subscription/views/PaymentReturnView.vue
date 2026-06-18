<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { subscriptionService } from '@/services/subscription.service'
import { formatCurrency } from '@/utils/currency'
import type { SaasInvoice } from '@/types/entities'

const router = useRouter()

const invoice = ref<SaasInvoice | null>(null)
const loading = ref(true)
const error   = ref<string | null>(null)

// Polling : 3s × 10 = 30s max si la facture reste "pending"
const POLL_INTERVAL_MS = 3_000
const POLL_MAX_ATTEMPTS = 10
let pollAttempts  = 0
let pollTimerId: number | null = null
const pollTimedOut = ref(false)

async function fetchLatestInvoice(): Promise<void> {
  error.value = null
  try {
    const invoices = await subscriptionService.getInvoices()
    invoice.value = invoices[0] ?? null
    if (invoice.value === null) {
      error.value = "Aucune facture n'a été trouvée."
    }
  } catch {
    error.value = "Impossible de vérifier l'état du paiement."
  }
}

function clearPoll(): void {
  if (pollTimerId !== null) {
    window.clearTimeout(pollTimerId)
    pollTimerId = null
  }
}

function schedulePoll(): void {
  clearPoll()
  if (pollAttempts >= POLL_MAX_ATTEMPTS) {
    pollTimedOut.value = true
    return
  }
  pollTimerId = window.setTimeout(async () => {
    pollAttempts += 1
    await fetchLatestInvoice()
    if (invoice.value?.status === 'pending') {
      schedulePoll()
    }
  }, POLL_INTERVAL_MS)
}

async function refreshManually(): Promise<void> {
  pollAttempts = 0
  pollTimedOut.value = false
  clearPoll()
  loading.value = true
  await fetchLatestInvoice()
  loading.value = false
  if (invoice.value?.status === 'pending') {
    schedulePoll()
  }
}

function goToSubscription(): void {
  router.push('/subscription')
}

function goToInvoices(): void {
  router.push('/subscription/invoices')
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('fr-FR', {
    day: '2-digit', month: 'long', year: 'numeric',
  })
}

onMounted(async () => {
  await fetchLatestInvoice()
  loading.value = false
  if (invoice.value?.status === 'pending') {
    schedulePoll()
  }
})

onBeforeUnmount(clearPoll)
</script>

<template>
  <div class="payment-return">
    <div class="card payment-card">
<!-- ── Chargement initial ── -->
      <template v-if="loading">
        <div class="payment-icon payment-icon-neutral">
          <i class="ti ti-loader-2 spin" aria-hidden="true"></i>
        </div>
        <h1 class="payment-title">Vérification en cours…</h1>
      </template>

      <!-- ── Erreur de chargement ── -->
      <template v-else-if="error && !invoice">
        <div class="payment-icon payment-icon-warning">
          <i class="ti ti-alert-triangle" aria-hidden="true"></i>
        </div>
        <h1 class="payment-title">Vérification impossible</h1>
        <p class="payment-lead">{{ error }}</p>
        <div class="payment-actions">
          <button class="btn btn-primary" @click="refreshManually">
            <i class="ti ti-refresh" aria-hidden="true"></i>
            Réessayer
          </button>
          <button class="btn btn-secondary" @click="goToInvoices">
            Voir mes factures
          </button>
        </div>
      </template>

      <!-- ── Paiement confirmé ── -->
      <template v-else-if="invoice?.status === 'paid'">
        <div class="payment-icon payment-icon-success">
          <i class="ti ti-circle-check" aria-hidden="true"></i>
        </div>
        <h1 class="payment-title">Paiement confirmé</h1>
        <p class="payment-lead">
          Merci, votre paiement a bien été enregistré.
        </p>
        <dl class="payment-recap">
          <div>
            <dt>Facture</dt>
            <dd class="t-mono">{{ invoice.number }}</dd>
          </div>
          <div>
            <dt>Plan</dt>
            <dd>{{ invoice.planName }}</dd>
          </div>
          <div>
            <dt>Montant</dt>
            <dd>{{ formatCurrency(invoice.amountXof) }}</dd>
          </div>
          <div>
            <dt>Période</dt>
            <dd>{{ formatDate(invoice.periodStart) }} → {{ formatDate(invoice.periodEnd) }}</dd>
          </div>
        </dl>
        <div class="payment-actions">
          <button class="btn btn-primary" @click="goToSubscription">
            Voir mon abonnement
          </button>
          <button class="btn btn-secondary" @click="goToInvoices">
            Mes factures
          </button>
        </div>
      </template>

      <!-- ── Paiement en attente d'IPN ── -->
      <template v-else-if="invoice?.status === 'pending'">
        <div class="payment-icon payment-icon-info">
          <i class="ti ti-clock" aria-hidden="true"></i>
        </div>
        <h1 class="payment-title">Paiement en cours de confirmation</h1>
        <p class="payment-lead">
          Nous attendons la confirmation de Paydunya. Cela peut
          prendre quelques instants — cette page se mettra à jour
          automatiquement.
        </p>
        <p v-if="pollTimedOut" class="payment-hint">
          La confirmation tarde. Si vous avez bien validé le paiement,
          patientez quelques minutes puis actualisez. Si rien ne
          change, contactez le support.
        </p>
        <div class="payment-actions">
          <button class="btn btn-primary" @click="refreshManually">
            <i class="ti ti-refresh" aria-hidden="true"></i>
            Actualiser
          </button>
          <button class="btn btn-secondary" @click="goToInvoices">
            Voir mes factures
          </button>
        </div>
      </template>

      <!-- ── Paiement échoué ── -->
      <template v-else-if="invoice?.status === 'failed'">
        <div class="payment-icon payment-icon-danger">
          <i class="ti ti-alert-circle" aria-hidden="true"></i>
        </div>
        <h1 class="payment-title">Paiement non confirmé</h1>
        <p class="payment-lead">
          Le paiement n'a pas pu être confirmé. Aucune somme n'a été
          débitée — vous pouvez réessayer depuis l'historique de vos
          factures.
        </p>
        <div class="payment-actions">
          <button class="btn btn-primary" @click="goToInvoices">
            Réessayer
          </button>
          <button class="btn btn-secondary" @click="goToSubscription">
            Mon abonnement
          </button>
        </div>
      </template>

      <!-- ── Statut inattendu (draft, cancelled) ── -->
      <template v-else>
        <div class="payment-icon payment-icon-neutral">
          <i class="ti ti-file-invoice" aria-hidden="true"></i>
        </div>
        <h1 class="payment-title">Facture non payable</h1>
        <p class="payment-lead">
          Cette facture n'est pas dans un état permettant un paiement.
        </p>
        <div class="payment-actions">
          <button class="btn btn-primary" @click="goToInvoices">
            Voir mes factures
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.payment-return {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: calc(100vh - 4rem);
  padding: 3rem 1.5rem;
}

.payment-card {
  max-width: 520px;
  width: 100%;
  padding: 2.5rem 2rem;
  text-align: center;
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
}

.payment-icon {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1.25rem;
}
.payment-icon i { font-size: 32px; }

.payment-icon-success { background: var(--pms-green-light); color: var(--pms-green); }
.payment-icon-info    { background: var(--pms-blue-light);  color: var(--pms-blue); }
.payment-icon-warning { background: var(--pms-gold-light);  color: var(--pms-gold-dark); }
.payment-icon-danger  { background: var(--pms-red-light);   color: var(--pms-red); }
.payment-icon-neutral { background: var(--pms-sand-2);      color: var(--pms-ink-2); }

.payment-title {
  font-size: 22px;
  font-weight: 500;
  color: var(--pms-ink);
  margin: 0 0 0.75rem;
}

.payment-lead {
  font-size: 14px;
  color: var(--pms-ink-2);
  line-height: 1.55;
  margin: 0 0 1.5rem;
}

.payment-hint {
  font-size: 12px;
  color: var(--pms-ink-3);
  line-height: 1.5;
  margin: 0 0 1.5rem;
}

.payment-recap {
  background: var(--pms-sand);
  border-radius: var(--radius-md);
  padding: 1rem 1.25rem;
  margin: 0 0 1.5rem;
  display: grid;
  gap: 0.6rem;
}
.payment-recap > div {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
}
.payment-recap dt {
  font-size: 11px;
  font-weight: 500;
  color: var(--pms-ink-3);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin: 0;
}
.payment-recap dd {
  font-size: 13px;
  color: var(--pms-ink);
  margin: 0;
  text-align: right;
}
.t-mono {
  font-family: var(--mono);
  font-size: 12px;
  color: var(--pms-teal);
}

.payment-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
}

.spin {
  animation: spin 1s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
