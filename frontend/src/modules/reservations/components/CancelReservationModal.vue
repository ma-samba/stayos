<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { reservationService } from '@/services/reservation.service'
import type { Reservation } from '@/types/entities'
import type { CancellationQuote } from '@/types/financial-policies'

// ──────────────────────────────────────────────────────────────
//  CancelReservationModal — Sprint 13quinquies-A
//  Fetch un quote au mount, affiche frais + délai, permet d'override
//  le montant (geste commercial) et exige un reason >= 5 chars.
// ──────────────────────────────────────────────────────────────

const props = defineProps<{
  reservation: Reservation
  isOpen: boolean
  submitting?: boolean
}>()

const emit = defineEmits<{
  close: []
  confirm: [payload: { reason: string; feeOverrideXof?: string }]
}>()

const MIN_REASON_LENGTH = 5

const quote = ref<CancellationQuote | null>(null)
const quoteLoading = ref(false)
const quoteError = ref<string | null>(null)
const reason = ref('')
const feeInput = ref<string>('') // valeur affichée dans l'input
const overrideEnabled = ref(false)

const trimmedReason = computed(() => reason.value.trim())
const isReasonValid = computed(() => trimmedReason.value.length >= MIN_REASON_LENGTH)

const effectiveFee = computed<string>(() => {
  if (overrideEnabled.value) {
    return feeInput.value === '' ? '0' : feeInput.value
  }
  return quote.value?.amountXof ?? '0'
})

const policyLabel = (p: string): string => {
  switch (p) {
    case 'flexible': return 'Flexible (gratuit)'
    case 'moderate': return 'Modérée (selon préavis)'
    case 'strict':   return 'Stricte (1ère nuit retenue)'
    default:         return p
  }
}

async function loadQuote(): Promise<void> {
  quoteLoading.value = true
  quoteError.value = null
  try {
    quote.value = await reservationService.getCancellationQuote(props.reservation.id)
    feeInput.value = quote.value.amountXof
  } catch (e: unknown) {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const resp = (e as any)?.response?.data
    quoteError.value = resp?.error ?? 'Erreur lors du calcul des frais. Veuillez réessayer.'
    quote.value = null
  } finally {
    quoteLoading.value = false
  }
}

watch(() => props.isOpen, (open) => {
  if (open) {
    reason.value = ''
    overrideEnabled.value = false
    quote.value = null
    feeInput.value = ''
    loadQuote()
  }
})

onMounted(() => {
  if (props.isOpen) {
    // Cas où le composant est monté via v-if avec isOpen
    // déjà à true (ex: ReservationsView). Le watch ne se
    // déclenche pas dans ce cas car il n'y a pas de
    // transition false → true.
    loadQuote()
  }
})

function fmtXof(v: string | number): string {
  const n = Number(v)
  if (Number.isNaN(n)) return String(v)
  return new Intl.NumberFormat('fr-FR').format(n) + ' XOF'
}

function fmtHours(h: number): string {
  if (h >= 48) return `${Math.floor(h / 24)} jour(s)`
  return `${h} h`
}

function confirm(): void {
  if (!isReasonValid.value) return
  const feeOverride = overrideEnabled.value && quote.value !== null
    && feeInput.value !== quote.value.amountXof
    ? feeInput.value
    : undefined
  emit('confirm', {
    reason: trimmedReason.value,
    feeOverrideXof: feeOverride,
  })
}
</script>

<template>
  <div v-if="isOpen" class="modal-backdrop" @click.self="emit('close')">
    <div class="modal">
      <header class="modal-header">
        <h2>Annuler — {{ reservation.confirmationNumber }}</h2>
        <button class="btn btn-ghost btn-sm" aria-label="Fermer" @click="emit('close')">
          <i class="ti ti-x" aria-hidden="true"></i>
        </button>
      </header>

      <div class="modal-body">
        <div v-if="quoteLoading" class="t-muted">Calcul des frais…</div>

        <div v-else-if="quoteError" class="error-box">
          <i class="ti ti-alert-circle"></i>
          <span>{{ quoteError }}</span>
          <button class="btn btn-sm btn-ghost" @click="loadQuote()">
            Réessayer
          </button>
        </div>

        <template v-else-if="quote">
          <div class="quote-grid">
            <div class="quote-item">
              <span class="t-label">Politique tenant</span>
              <span class="quote-value">{{ policyLabel(quote.policy) }}</span>
            </div>
            <div class="quote-item">
              <span class="t-label">Délai avant arrivée</span>
              <span class="quote-value">{{ fmtHours(quote.hoursBefore) }}</span>
            </div>
          </div>

          <div class="reason-box">{{ quote.reason }}</div>

          <div class="fee-row">
            <span class="t-label">Frais à facturer</span>
            <span class="fee-value">{{ fmtXof(effectiveFee) }}</span>
          </div>

          <div class="override-block">
            <label class="override-toggle">
              <input v-model="overrideEnabled" type="checkbox" />
              <span>Modifier le montant (geste commercial)</span>
            </label>
            <input
              v-if="overrideEnabled"
              v-model="feeInput"
              type="number"
              min="0"
              step="500"
              class="input"
              placeholder="Montant XOF"
            />
            <p v-if="overrideEnabled" class="input-hint">
              L'override est tracé dans le journal d'audit.
            </p>
          </div>

          <div class="input-wrap">
            <span class="input-label">Raison de l'annulation</span>
            <textarea
              v-model="reason"
              class="input textarea"
              rows="2"
              placeholder="Ex. : Client a annulé par téléphone"
            />
            <span class="input-hint" :class="{ 'is-invalid': trimmedReason.length > 0 && !isReasonValid }">
              {{ trimmedReason.length }} / {{ MIN_REASON_LENGTH }} caractères minimum
            </span>
          </div>
        </template>
      </div>

      <footer class="modal-footer">
        <button class="btn btn-ghost" :disabled="submitting" @click="emit('close')">
          Retour
        </button>
        <button
          class="btn btn-danger"
          :disabled="submitting || quoteLoading || !isReasonValid"
          @click="confirm"
        >
          <i v-if="submitting" class="ti ti-loader animate-spin" aria-hidden="true"></i>
          Confirmer l'annulation
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 50; }
.modal { background: #fff; border-radius: 16px; width: 560px; max-width: 92vw; max-height: 90vh; display: flex; flex-direction: column; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 0.5px solid var(--pms-border); }
.modal-header h2 { font-size: 15px; font-weight: 500; margin: 0; }
.modal-body { padding: 20px 22px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 22px; border-top: 0.5px solid var(--pms-border); }

.t-muted { color: var(--pms-ink-3); font-size: 13px; }
.t-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--pms-ink-3); font-weight: 500; }

.quote-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.quote-item {
  background: var(--pms-sand, #F5F0E8);
  border-radius: 10px;
  padding: 10px 14px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.quote-value { font-size: 13px; font-weight: 500; color: var(--pms-ink); }

.reason-box {
  font-size: 12px;
  color: var(--pms-ink-2);
  font-style: italic;
  padding: 8px 0;
}

.fee-row {
  background: #F5DADA;
  border-left: 3px solid #B83232;
  border-radius: 8px;
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.fee-value { font-size: 18px; font-weight: 600; color: #B83232; }

.override-block { display: flex; flex-direction: column; gap: 6px; }
.override-toggle { display: flex; gap: 8px; align-items: center; font-size: 12px; color: var(--pms-ink-2); cursor: pointer; }
.override-toggle input { cursor: pointer; }

.input-wrap { display: flex; flex-direction: column; gap: 6px; }
.input-label { font-size: 11px; font-weight: 500; color: var(--pms-ink-3); letter-spacing: 0.04em; text-transform: uppercase; }
.input { padding: 8px 14px; border: 0.5px solid var(--pms-border-2); border-radius: 10px; font-family: var(--font); font-size: 13px; background: #fff; }
.textarea { padding: 10px 14px; min-height: 60px; resize: vertical; }
.input-hint { font-size: 11px; color: var(--pms-ink-3); }
.input-hint.is-invalid { color: var(--pms-red, #B83232); }

.btn { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 16px; border-radius: 10px; border: none; font-family: var(--font); font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; color: var(--pms-ink-3); }
.btn-danger { background: #B83232; color: #fff; }
.btn-danger:hover:not(:disabled) { background: #8C2424; }
.btn-sm { height: 30px; padding: 0 12px; font-size: 12px; }

.animate-spin { animation: spin 0.9s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.error-box {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #F5DADA;
  border-left: 3px solid #B83232;
  border-radius: 8px;
  padding: 12px 16px;
  color: #8C2424;
  font-size: 13px;
}
.error-box .btn { margin-left: auto; }
</style>
