<script setup lang="ts">
import { ref, computed } from 'vue'
import type { DailyClose } from '@/types/night-audit'

// ──────────────────────────────────────────────────────────────
//  ReopenModal — Sprint 13quater-C
//  MANAGER uniquement. Min 5 chars sur reason (validé serveur aussi).
// ──────────────────────────────────────────────────────────────

const props = defineProps<{
  close: DailyClose | null
  isOpen: boolean
  submitting?: boolean
}>()

const emit = defineEmits<{
  close: []
  confirm: [reason: string]
}>()

const MIN_LENGTH = 5
const reason = ref('')

const trimmedLength = computed(() => reason.value.trim().length)
const isValid = computed(() => trimmedLength.value >= MIN_LENGTH)

const businessDate = computed(() => {
  if (!props.close) return ''
  return formatDate(props.close.businessDate)
})

function formatDate(iso: string): string {
  // Le back retourne soit 'YYYY-MM-DD' soit 'YYYY-MM-DDTHH:mm:ss+TZ'
  const datePart = iso.split('T')[0]
  const [y, m, d] = datePart.split('-')
  if (!y || !m || !d) return iso
  return `${d}/${m}/${y}`
}

function submit(): void {
  if (!isValid.value) return
  emit('confirm', reason.value.trim())
}

function onClose(): void {
  reason.value = ''
  emit('close')
}
</script>

<template>
  <div v-if="isOpen && close" class="modal-backdrop" @click.self="onClose">
    <div class="modal">
      <header class="modal-header">
        <h2>Réouvrir la clôture du {{ businessDate }}&nbsp;?</h2>
        <button class="btn btn-ghost btn-sm" aria-label="Fermer" @click="onClose">
          <i class="ti ti-x" aria-hidden="true"></i>
        </button>
      </header>

      <div class="modal-body">
        <div class="warning-banner">
          <i class="ti ti-alert-circle" aria-hidden="true"></i>
          <p>
            La réouverture permet de modifier à nouveau les opérations de cette
            journée. Cette action sera tracée dans le journal d'audit.
          </p>
        </div>

        <div class="input-wrap">
          <label class="input-label">Raison de la réouverture</label>
          <textarea
            class="input textarea"
            v-model="reason"
            rows="3"
            placeholder="Ex: correction d'une facture mal saisie le matin"
            :disabled="submitting"
          />
          <div class="char-counter" :class="{ 'is-invalid': trimmedLength > 0 && !isValid }">
            {{ trimmedLength }} / {{ MIN_LENGTH }} caractères minimum
          </div>
        </div>
      </div>

      <footer class="modal-footer">
        <button class="btn btn-ghost" :disabled="submitting" @click="onClose">
          Annuler
        </button>
        <button
          class="btn btn-primary"
          :disabled="!isValid || submitting"
          @click="submit"
        >
          <i v-if="submitting" class="ti ti-loader animate-spin" aria-hidden="true"></i>
          Réouvrir
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 50; }
.modal { background: #fff; border-radius: 16px; width: 520px; max-width: 92vw; max-height: 90vh; display: flex; flex-direction: column; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 0.5px solid var(--pms-border); }
.modal-header h2 { font-size: 15px; font-weight: 500; margin: 0; }
.modal-body { padding: 20px 22px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 22px; border-top: 0.5px solid var(--pms-border); }

.warning-banner {
  display: flex; gap: 10px;
  padding: 10px 12px;
  background: #FBE5E5;
  border-left: 3px solid #B83232;
  border-radius: 8px;
  color: #B83232;
}
.warning-banner i { font-size: 18px; flex-shrink: 0; margin-top: 2px; }
.warning-banner p { margin: 0; font-size: 12px; line-height: 1.45; color: #6B6459; }

.input-wrap { display: flex; flex-direction: column; gap: 6px; }
.input-label { font-size: 11px; font-weight: 500; color: var(--pms-ink-3); letter-spacing: 0.04em; text-transform: uppercase; }
.input { padding: 10px 14px; border: 0.5px solid var(--pms-border-2); border-radius: 10px; font-family: var(--font); font-size: 13px; background: #fff; resize: vertical; }
.textarea { min-height: 80px; }

.char-counter { font-size: 11px; color: var(--pms-ink-3); }
.char-counter.is-invalid { color: var(--pms-red, #B83232); }

.btn { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 16px; border-radius: 10px; border: none; font-family: var(--font); font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; color: var(--pms-ink-3); }
.btn-primary { background: var(--pms-ink); color: #fff; }
.btn-sm { height: 30px; padding: 0 12px; font-size: 12px; }

.animate-spin { animation: spin 0.9s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
