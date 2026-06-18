<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import type { Promotion } from '@/types/entities'
import { rateService } from '@/services/rate.service'

const emit = defineEmits<{
  close: []
  saved: []
}>()

const props = defineProps<{
  promotion?: Promotion | null
}>()

const code           = ref('')
const description    = ref('')
const type           = ref<'percentage' | 'fixed'>('percentage')
const value          = ref('')
const maxDiscountXof = ref('')
const minNights      = ref(1)
const minAmountXof   = ref('')
const validFrom      = ref('')
const validTo        = ref('')
const maxUses        = ref<number | ''>('')
const isActive       = ref(true)

const showAdvanced = ref(false)

const submitting = ref(false)
const formError  = ref<string | null>(null)

const valueLabel = computed(() =>
  type.value === 'percentage' ? '% remise' : 'Montant XOF',
)

onMounted(() => {
  if (props.promotion) {
    const p = props.promotion
    code.value           = p.code
    description.value    = p.description ?? ''
    type.value           = p.type
    value.value          = p.value
    maxDiscountXof.value = p.maxDiscountXof ?? ''
    minNights.value      = p.minNights
    minAmountXof.value   = p.minAmountXof ?? ''
    validFrom.value      = p.validFrom?.slice(0, 10) ?? ''
    validTo.value        = p.validTo?.slice(0, 10) ?? ''
    maxUses.value        = p.maxUses ?? ''
    isActive.value       = p.isActive
  }
})

async function submit(): Promise<void> {
  if (!code.value.trim() || !value.value) {
    formError.value = 'Le code et la valeur sont obligatoires.'
    return
  }

  submitting.value = true
  formError.value  = null

  const payload: Record<string, unknown> = {
    code:           code.value.trim().toUpperCase(),
    description:    description.value || null,
    type:           type.value,
    value:          value.value,
    minNights:      minNights.value,
    isActive:       isActive.value,
    maxDiscountXof: maxDiscountXof.value || null,
    minAmountXof:   minAmountXof.value || null,
    validFrom:      validFrom.value || null,
    validTo:        validTo.value || null,
    maxUses:        maxUses.value !== '' ? Number(maxUses.value) : null,
  }

  try {
    if (props.promotion) {
      await rateService.updatePromotion(props.promotion.id, payload as Partial<Promotion>)
    } else {
      await rateService.createPromotion(payload as Partial<Promotion>)
    }
    emit('saved')
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'response' in e) {
      const err = e as { response: { data: { error?: string } } }
      formError.value = err.response?.data?.error ?? 'Erreur lors de l\'enregistrement'
    } else {
      formError.value = 'Erreur lors de l\'enregistrement'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div
    style="position:fixed; inset:0; background:rgba(26,23,20,0.4); display:flex; align-items:center; justify-content:center; z-index:100;"
    @click.self="emit('close')"
  >
    <div style="background:#fff; border-radius:var(--radius-xl); padding:1.5rem; width:520px; max-width:90vw; max-height:90vh; overflow-y:auto;">
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
        <h3 style="font-size:18px; font-weight:500; color:var(--pms-ink);">
          {{ promotion ? 'Modifier la promotion' : 'Nouvelle promotion' }}
        </h3>
        <button class="btn btn-ghost btn-icon-sm" @click="emit('close')">
          <i class="ti ti-x" aria-hidden="true"></i>
        </button>
      </div>

      <div v-if="formError" style="background:var(--pms-red-light); color:var(--pms-red); padding:10px 14px; border-radius:var(--radius-md); margin-bottom:1rem; font-size:13px;">
        <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ formError }}
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Code *</label>
          <input v-model="code" class="input" placeholder="OUVERTURE2026" style="text-transform:uppercase;" />
        </div>
        <div class="input-wrap">
          <label class="input-label">Description</label>
          <input v-model="description" class="input" placeholder="Offre de lancement" />
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Type *</label>
          <select v-model="type" class="select">
            <option value="percentage">Pourcentage</option>
            <option value="fixed">Montant fixe</option>
          </select>
        </div>
        <div class="input-wrap">
          <label class="input-label">{{ valueLabel }} *</label>
          <input v-model="value" class="input" type="text" inputmode="decimal" :placeholder="type === 'percentage' ? '10.00' : '5000.00'" />
        </div>
      </div>

      <!-- Plafond remise (visible si percentage) -->
      <div v-if="type === 'percentage'" class="input-wrap" style="margin-bottom:1rem;">
        <label class="input-label">Plafond remise XOF (optionnel)</label>
        <input v-model="maxDiscountXof" class="input" type="text" inputmode="decimal" placeholder="25000.00" />
        <span class="input-hint">Montant maximum de la remise</span>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Nuits minimum</label>
          <input v-model.number="minNights" class="input" type="number" min="1" />
        </div>
        <div class="input-wrap">
          <label class="input-label">Utilisations max (optionnel)</label>
          <input v-model.number="maxUses" class="input" type="number" min="1" placeholder="Illimite" />
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Valide du (optionnel)</label>
          <input v-model="validFrom" class="input" type="date" />
        </div>
        <div class="input-wrap">
          <label class="input-label">Valide au (optionnel)</label>
          <input v-model="validTo" class="input" type="date" />
        </div>
      </div>

      <!-- Conditions avancees -->
      <button
        class="btn btn-ghost btn-sm"
        style="margin-bottom:0.75rem; color:var(--pms-ink-3);"
        @click="showAdvanced = !showAdvanced"
      >
        <i :class="showAdvanced ? 'ti ti-chevron-up' : 'ti ti-chevron-down'" aria-hidden="true"></i>
        Conditions avancees
      </button>

      <div v-if="showAdvanced" style="margin-bottom:1rem;">
        <div class="input-wrap" style="margin-bottom:0.75rem;">
          <label class="input-label">Montant minimum du sejour XOF (optionnel)</label>
          <input v-model="minAmountXof" class="input" type="text" inputmode="decimal" placeholder="100000.00" />
        </div>
      </div>

      <div style="display:flex; align-items:center; gap:8px; margin-bottom:1.5rem;">
        <button
          :class="['toggle', isActive ? 'on' : '']"
          @click="isActive = !isActive"
        ></button>
        <span style="font-size:13px; color:var(--pms-ink-2);">Actif</span>
      </div>

      <div style="display:flex; gap:8px; justify-content:flex-end;">
        <button class="btn btn-ghost" @click="emit('close')">Annuler</button>
        <button class="btn btn-primary" :disabled="submitting" @click="submit()">
          <span v-if="submitting" class="spinner" style="width:14px; height:14px; border-width:1.5px;"></span>
          <template v-else>
            <i class="ti ti-check" aria-hidden="true"></i>
            {{ promotion ? 'Enregistrer' : 'Creer' }}
          </template>
        </button>
      </div>
</div>
  </div>
</template>
