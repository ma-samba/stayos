<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { tenantSettingsService } from '@/services/tenant-settings.service'
import type { Reservation } from '@/types/entities'
import type { NoShowPolicy, TenantSettings } from '@/types/financial-policies'

// ──────────────────────────────────────────────────────────────
//  MarkNoShowModal — Sprint 13quinquies-A
//  Modal de confirmation no-show avec récap politique tenant +
//  override possible (geste commercial) + total live.
// ──────────────────────────────────────────────────────────────

const props = defineProps<{
  reservation: Reservation
  isOpen: boolean
  submitting?: boolean
}>()

const emit = defineEmits<{
  close: []
  confirm: [policyOverride: NoShowPolicy | undefined]
}>()

const settings = ref<TenantSettings | null>(null)
const settingsLoading = ref(false)

// Override sélectionné — null = utiliser la politique tenant
const overrideSelection = ref<NoShowPolicy | 'tenant'>('tenant')

const effectivePolicy = computed<NoShowPolicy>(() => {
  if (overrideSelection.value !== 'tenant') {
    return overrideSelection.value
  }
  return settings.value?.noShowPolicy ?? 'first_night'
})

const feeXof = computed<string>(() => {
  switch (effectivePolicy.value) {
    case 'none':        return '0'
    case 'first_night': return props.reservation.rateXof
    case 'full':        return props.reservation.totalXof
    default:            return '0'
  }
})

const policyLabel = (p: NoShowPolicy): string => {
  switch (p) {
    case 'none':        return 'Aucun frais'
    case 'first_night': return '1ère nuit'
    case 'full':        return 'Total du séjour'
  }
}

const tenantPolicyLabel = computed(() =>
  settings.value ? policyLabel(settings.value.noShowPolicy) : '…',
)

async function loadSettings(): Promise<void> {
  settingsLoading.value = true
  try {
    settings.value = await tenantSettingsService.get()
  } finally {
    settingsLoading.value = false
  }
}

watch(() => props.isOpen, (open) => {
  if (open && settings.value === null) {
    loadSettings()
  }
  if (open) {
    overrideSelection.value = 'tenant'
  }
})

onMounted(() => {
  if (props.isOpen) loadSettings()
})

function fmtXof(v: string | number): string {
  const n = Number(v)
  if (Number.isNaN(n)) return String(v)
  return new Intl.NumberFormat('fr-FR').format(n) + ' XOF'
}

function confirm(): void {
  const override = overrideSelection.value === 'tenant'
    ? undefined
    : overrideSelection.value
  emit('confirm', override)
}
</script>

<template>
  <div v-if="isOpen" class="modal-backdrop" @click.self="emit('close')">
    <div class="modal">
      <header class="modal-header">
        <h2>Marquer no-show — {{ reservation.confirmationNumber }}</h2>
        <button class="btn btn-ghost btn-sm" aria-label="Fermer" @click="emit('close')">
          <i class="ti ti-x" aria-hidden="true"></i>
        </button>
      </header>

      <div class="modal-body">
        <p class="lead">
          La réservation sera passée au statut <strong>NO_SHOW</strong>.
          Selon la politique de l'hôtel, des frais peuvent être facturés.
        </p>

        <div v-if="settingsLoading" class="t-muted">Chargement des paramètres…</div>

        <template v-else>
          <div class="policy-card">
            <span class="t-label">Politique configurée</span>
            <span class="policy-value">{{ tenantPolicyLabel }}</span>
          </div>

          <div class="input-wrap">
            <span class="input-label">Appliquer</span>
            <select v-model="overrideSelection" class="input">
              <option value="tenant">Politique tenant ({{ tenantPolicyLabel }})</option>
              <option value="none">Aucun frais</option>
              <option value="first_night">1ère nuit ({{ fmtXof(reservation.rateXof) }})</option>
              <option value="full">Total du séjour ({{ fmtXof(reservation.totalXof) }})</option>
            </select>
            <span v-if="overrideSelection !== 'tenant'" class="input-hint">
              Override = geste commercial, tracé dans l'audit log.
            </span>
          </div>

          <div class="fee-row">
            <span class="t-label">Montant facturé</span>
            <span class="fee-value">{{ fmtXof(feeXof) }}</span>
          </div>
        </template>
      </div>

      <footer class="modal-footer">
        <button class="btn btn-ghost" :disabled="submitting" @click="emit('close')">
          Annuler
        </button>
        <button
          class="btn btn-warning"
          :disabled="submitting || settingsLoading"
          @click="confirm"
        >
          <i v-if="submitting" class="ti ti-loader animate-spin" aria-hidden="true"></i>
          Confirmer le no-show
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

.lead { font-size: 13px; color: var(--pms-ink-2); line-height: 1.5; margin: 0; }
.t-muted { color: var(--pms-ink-3); font-size: 13px; }
.t-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--pms-ink-3); font-weight: 500; }

.policy-card {
  background: var(--pms-sand, #F5F0E8);
  border-radius: 10px;
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.policy-value { font-size: 13px; font-weight: 500; color: var(--pms-ink); }

.input-wrap { display: flex; flex-direction: column; gap: 6px; }
.input-label { font-size: 11px; font-weight: 500; color: var(--pms-ink-3); letter-spacing: 0.04em; text-transform: uppercase; }
.input { height: 38px; padding: 0 14px; border: 0.5px solid var(--pms-border-2); border-radius: 10px; font-family: var(--font); font-size: 13px; background: #fff; }
.input-hint { font-size: 11px; color: #8A6319; }

.fee-row {
  background: #FBF6E8;
  border-left: 3px solid #C4922A;
  border-radius: 8px;
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.fee-value { font-size: 18px; font-weight: 600; color: #8A6319; }

.btn { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 16px; border-radius: 10px; border: none; font-family: var(--font); font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; color: var(--pms-ink-3); }
.btn-warning { background: #C4922A; color: #fff; }
.btn-warning:hover:not(:disabled) { background: #8A6319; }
.btn-sm { height: 30px; padding: 0 12px; font-size: 12px; }

.animate-spin { animation: spin 0.9s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
