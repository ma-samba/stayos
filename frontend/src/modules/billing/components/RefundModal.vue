<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import type { Invoice } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  RefundModal — Sprint 13quinquies-B
//  Modale de remboursement avec montant pré-rempli au paidXof et
//  validation client (>0, <=paidXof, reason >=5 chars).
// ──────────────────────────────────────────────────────────────

const props = defineProps<{
  invoice: Invoice
  isOpen: boolean
  submitting?: boolean
}>()

const emit = defineEmits<{
  close: []
  confirm: [payload: { amountXof: string; method: string; reason: string }]
}>()

const MIN_REASON = 5

const methods = [
  { value: 'cash',          label: 'Espèces' },
  { value: 'wave',          label: 'Wave' },
  { value: 'orange_money',  label: 'Orange Money' },
  { value: 'card',          label: 'Carte' },
  { value: 'bank_transfer', label: 'Virement bancaire' },
]

const amountInput   = ref<string>('')
const methodInput   = ref<string>('cash')
const reasonInput   = ref<string>('')

const paidXofNum = computed<number>(() => Number(props.invoice.paidXof) || 0)
const amountNum  = computed<number>(() => Number(amountInput.value) || 0)

const reasonTrimmed = computed(() => reasonInput.value.trim())

const amountValid = computed(() =>
  amountNum.value > 0 && amountNum.value <= paidXofNum.value,
)
const reasonValid = computed(() => reasonTrimmed.value.length >= MIN_REASON)
const formValid   = computed(() => amountValid.value && reasonValid.value)

const remainingAfterRefund = computed<number>(() =>
  Math.max(0, paidXofNum.value - amountNum.value),
)

function reset(): void {
  amountInput.value = props.invoice.paidXof
  methodInput.value = 'cash'
  reasonInput.value = ''
}

watch(() => props.isOpen, (open) => {
  if (open) reset()
})

function fmtXof(v: string | number): string {
  const n = typeof v === 'number' ? v : Number(v)
  if (Number.isNaN(n)) return String(v)
  return new Intl.NumberFormat('fr-FR').format(n) + ' XOF'
}

function confirm(): void {
  if (!formValid.value) return
  emit('confirm', {
    amountXof: amountInput.value,
    method: methodInput.value,
    reason: reasonTrimmed.value,
  })
}
</script>

<template>
  <div v-if="isOpen" class="modal-backdrop" @click.self="emit('close')">
    <div class="modal">
      <header class="modal-header">
        <h2>Rembourser — facture {{ invoice.number }}</h2>
        <button class="btn btn-ghost btn-sm" aria-label="Fermer" @click="emit('close')">
          <i class="ti ti-x" aria-hidden="true"></i>
        </button>
      </header>

      <div class="modal-body">
        <!-- Bandeau d'info important : StayOS ne fait que tracer -->
        <div class="info-banner">
          <i class="ti ti-info-circle" aria-hidden="true"></i>
          <p>
            Le remboursement effectif (transfert Wave, Orange Money, etc.) doit
            être fait manuellement par votre agent client. StayOS trace
            l'opération comptablement.
          </p>
        </div>

        <!-- Récap facture -->
        <div class="recap">
          <div class="recap-line">
            <span class="t-label">Payé sur cette facture</span>
            <span class="recap-value">{{ fmtXof(invoice.paidXof) }}</span>
          </div>
          <div class="recap-line">
            <span class="t-label">Total facture</span>
            <span class="recap-value">{{ fmtXof(invoice.totalXof) }}</span>
          </div>
        </div>

        <!-- Montant -->
        <div class="input-wrap">
          <label class="input-label">Montant à rembourser (XOF)</label>
          <input
            v-model="amountInput"
            type="number"
            min="0"
            step="500"
            class="input"
            :class="{ 'is-invalid': amountInput !== '' && !amountValid }"
            placeholder="Montant XOF"
          />
          <span class="input-hint" :class="{ 'is-invalid': amountInput !== '' && !amountValid }">
            <template v-if="amountInput === ''">
              Maximum&nbsp;: {{ fmtXof(paidXofNum) }}
            </template>
            <template v-else-if="amountNum <= 0">
              Le montant doit être strictement positif.
            </template>
            <template v-else-if="amountNum > paidXofNum">
              Maximum {{ fmtXof(paidXofNum) }} (montant payé).
            </template>
            <template v-else>
              Restera payé après remboursement&nbsp;: {{ fmtXof(remainingAfterRefund) }}
            </template>
          </span>
        </div>

        <!-- Méthode -->
        <div class="input-wrap">
          <label class="input-label">Méthode de remboursement</label>
          <select v-model="methodInput" class="input">
            <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
          <span class="input-hint">
            Peut différer de la méthode d'encaissement original.
          </span>
        </div>

        <!-- Raison -->
        <div class="input-wrap">
          <label class="input-label">Raison</label>
          <textarea
            v-model="reasonInput"
            class="input textarea"
            rows="2"
            placeholder="Ex: annulation client, double encaissement, geste commercial"
          />
          <span class="input-hint" :class="{ 'is-invalid': reasonTrimmed.length > 0 && !reasonValid }">
            {{ reasonTrimmed.length }} / {{ MIN_REASON }} caractères minimum
          </span>
        </div>
      </div>

      <footer class="modal-footer">
        <button class="btn btn-ghost" :disabled="submitting" @click="emit('close')">
          Annuler
        </button>
        <button
          class="btn btn-danger"
          :disabled="submitting || !formValid"
          @click="confirm"
        >
          <i v-if="submitting" class="ti ti-loader animate-spin" aria-hidden="true"></i>
          Confirmer le remboursement
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 50; }
.modal { background: #fff; border-radius: 16px; width: 540px; max-width: 92vw; max-height: 90vh; display: flex; flex-direction: column; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 0.5px solid var(--pms-border); }
.modal-header h2 { font-size: 15px; font-weight: 500; margin: 0; }
.modal-body { padding: 20px 22px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 22px; border-top: 0.5px solid var(--pms-border); }

.info-banner {
  display: flex; gap: 10px;
  padding: 10px 12px;
  background: #D4E2F5;
  border-left: 3px solid #2B5BA8;
  border-radius: 8px;
}
.info-banner i { font-size: 16px; color: #2B5BA8; flex-shrink: 0; margin-top: 2px; }
.info-banner p { margin: 0; font-size: 12px; line-height: 1.45; color: #1A1714; }

.recap { background: var(--pms-sand, #F5F0E8); border-radius: 10px; padding: 10px 14px; display: flex; flex-direction: column; gap: 6px; }
.recap-line { display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
.recap-value { font-weight: 500; color: var(--pms-ink); }
.t-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--pms-ink-3); font-weight: 500; }

.input-wrap { display: flex; flex-direction: column; gap: 6px; }
.input-label { font-size: 11px; font-weight: 500; color: var(--pms-ink-3); letter-spacing: 0.04em; text-transform: uppercase; }
.input { padding: 8px 14px; border: 0.5px solid var(--pms-border-2); border-radius: 10px; font-family: var(--font); font-size: 13px; background: #fff; height: 38px; }
.textarea { padding: 10px 14px; min-height: 60px; resize: vertical; height: auto; }
.input.is-invalid { border-color: var(--pms-red, #B83232); }
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
</style>
