<script setup lang="ts">
import { computed } from 'vue'
import type { NightAuditWarning } from '@/types/night-audit'
import WarningList from './WarningList.vue'

// ──────────────────────────────────────────────────────────────
//  ConfirmCloseModal — Sprint 13quater-C
//  Deux variantes selon présence ou non de warnings.
// ──────────────────────────────────────────────────────────────

const props = defineProps<{
  warnings: NightAuditWarning[]
  isOpen: boolean
  submitting?: boolean
}>()

const emit = defineEmits<{
  close: []
  confirm: [force: boolean]
}>()

const hasWarnings = computed(() => props.warnings.length > 0)

function confirm(): void {
  emit('confirm', hasWarnings.value) // force=true uniquement si warnings
}
</script>

<template>
  <div v-if="isOpen" class="modal-backdrop" @click.self="emit('close')">
    <div class="modal">
      <header class="modal-header">
        <h2 v-if="!hasWarnings">Clôturer la journée&nbsp;?</h2>
        <h2 v-else>Forcer la clôture avec {{ warnings.length }} avertissement(s)&nbsp;?</h2>
        <button
          class="btn btn-ghost btn-sm"
          aria-label="Fermer"
          @click="emit('close')"
        >
          <i class="ti ti-x" aria-hidden="true"></i>
        </button>
      </header>

      <div class="modal-body">
        <p v-if="!hasWarnings" class="lead">
          Cette action figera le snapshot de la journée et activera le verrou
          comptable. Toute modification ultérieure nécessitera une réouverture
          par le manager.
        </p>
        <template v-else>
          <p class="lead">
            Les avertissements ci-dessous seront enregistrés dans le snapshot
            et visibles sur la liasse PDF. Voulez-vous quand même clôturer&nbsp;?
          </p>
          <WarningList :warnings="warnings" dense />
        </template>
      </div>

      <footer class="modal-footer">
        <button
          class="btn btn-ghost"
          :disabled="submitting"
          @click="emit('close')"
        >
          Annuler
        </button>
        <button
          class="btn"
          :class="hasWarnings ? 'btn-warning' : 'btn-primary'"
          :disabled="submitting"
          @click="confirm"
        >
          <i v-if="submitting" class="ti ti-loader animate-spin" aria-hidden="true"></i>
          {{ hasWarnings ? 'Confirmer la clôture forcée' : 'Confirmer la clôture' }}
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

.lead { font-size: 13px; color: var(--pms-ink-2); line-height: 1.5; margin: 0; }

.btn { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 16px; border-radius: 10px; border: none; font-family: var(--font); font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; color: var(--pms-ink-3); }
.btn-primary { background: var(--pms-ink); color: #fff; }
.btn-warning { background: #C4922A; color: #fff; }
.btn-warning:hover:not(:disabled) { background: #8A6319; }
.btn-sm { height: 30px; padding: 0 12px; font-size: 12px; }

.animate-spin { animation: spin 0.9s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
