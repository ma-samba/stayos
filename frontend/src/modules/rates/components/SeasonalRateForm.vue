<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import type { SeasonalRate } from '@/types/entities'
import { rateService } from '@/services/rate.service'

const emit = defineEmits<{
  close: []
  saved: []
}>()

const props = defineProps<{
  rate?: SeasonalRate | null
}>()

const name      = ref('')
const type      = ref<'multiplier' | 'absolute'>('multiplier')
const value     = ref('')
const startDate = ref('')
const endDate   = ref('')
const priority  = ref(0)
const isActive  = ref(true)

const submitting = ref(false)
const formError  = ref<string | null>(null)

const valueLabel = computed(() =>
  type.value === 'multiplier' ? 'Multiplicateur (ex: 1.5)' : 'Tarif / nuit XOF',
)

onMounted(() => {
  if (props.rate) {
    name.value      = props.rate.name
    type.value      = props.rate.type
    value.value     = props.rate.value
    startDate.value = props.rate.startDate.slice(0, 10)
    endDate.value   = props.rate.endDate.slice(0, 10)
    priority.value  = props.rate.priority
    isActive.value  = props.rate.isActive
  }
})

async function submit(): Promise<void> {
  if (!name.value.trim() || !value.value || !startDate.value || !endDate.value) {
    formError.value = 'Tous les champs obligatoires doivent etre remplis.'
    return
  }
  if (endDate.value < startDate.value) {
    formError.value = 'La date de fin doit etre posterieure a la date de debut.'
    return
  }
  if (Number(value.value) <= 0) {
    formError.value = 'La valeur doit etre superieure a zero.'
    return
  }

  submitting.value = true
  formError.value  = null

  const payload = {
    name:      name.value.trim(),
    type:      type.value,
    value:     value.value,
    startDate: startDate.value,
    endDate:   endDate.value,
    priority:  priority.value,
    isActive:  isActive.value,
  }

  try {
    if (props.rate) {
      await rateService.updateSeasonal(props.rate.id, payload)
    } else {
      await rateService.createSeasonal(payload)
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
    <div style="background:#fff; border-radius:var(--radius-xl); padding:1.5rem; width:480px; max-width:90vw;">

      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
        <h3 style="font-size:18px; font-weight:500; color:var(--pms-ink);">
          {{ rate ? 'Modifier le tarif saisonnier' : 'Nouveau tarif saisonnier' }}
        </h3>
        <button class="btn btn-ghost btn-icon-sm" @click="emit('close')">
          <i class="ti ti-x" aria-hidden="true"></i>
        </button>
      </div>

      <div v-if="formError" style="background:var(--pms-red-light); color:var(--pms-red); padding:10px 14px; border-radius:var(--radius-md); margin-bottom:1rem; font-size:13px;">
        <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ formError }}
      </div>

      <div class="input-wrap" style="margin-bottom:1rem;">
        <label class="input-label">Nom *</label>
        <input v-model="name" class="input" placeholder="Haute saison" />
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Type *</label>
          <select v-model="type" class="select">
            <option value="multiplier">Multiplicateur</option>
            <option value="absolute">Tarif absolu</option>
          </select>
        </div>
        <div class="input-wrap">
          <label class="input-label">{{ valueLabel }} *</label>
          <input v-model="value" class="input" type="text" inputmode="decimal" :placeholder="type === 'multiplier' ? '1.50' : '65000.00'" />
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Date de debut *</label>
          <input v-model="startDate" class="input" type="date" />
        </div>
        <div class="input-wrap">
          <label class="input-label">Date de fin *</label>
          <input v-model="endDate" class="input" type="date" />
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Priorite</label>
          <input v-model.number="priority" class="input" type="number" min="0" />
          <span class="input-hint">Plus haute = appliquee en premier</span>
        </div>
        <div style="display:flex; align-items:center; gap:8px; padding-top:22px;">
          <button
            :class="['toggle', isActive ? 'on' : '']"
            @click="isActive = !isActive"
          ></button>
          <span style="font-size:13px; color:var(--pms-ink-2);">Actif</span>
        </div>
      </div>

      <div style="display:flex; gap:8px; justify-content:flex-end;">
        <button class="btn btn-ghost" @click="emit('close')">Annuler</button>
        <button class="btn btn-primary" :disabled="submitting" @click="submit()">
          <span v-if="submitting" class="spinner" style="width:14px; height:14px; border-width:1.5px;"></span>
          <template v-else>
            <i class="ti ti-check" aria-hidden="true"></i>
            {{ rate ? 'Enregistrer' : 'Creer' }}
          </template>
        </button>
      </div>

    </div>
  </div>
</template>
