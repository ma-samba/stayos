<script setup lang="ts">
import { ref, onMounted } from 'vue'
import type { RatePlan, RoomType } from '@/types/entities'
import { rateService } from '@/services/rate.service'
import { roomService } from '@/services/room.service'

const emit = defineEmits<{
  close: []
  saved: []
}>()

const props = defineProps<{
  plan?: RatePlan | null
}>()

const name        = ref('')
const baseRateXof = ref('')
const minNights   = ref(1)
const validFrom   = ref('')
const validTo     = ref('')
const isActive    = ref(true)
const roomTypeId  = ref('')

const roomTypes  = ref<RoomType[]>([])
const submitting = ref(false)
const formError  = ref<string | null>(null)

onMounted(async () => {
  try {
    roomTypes.value = await roomService.getTypes()
  } catch {
    // Non-blocking — select will just be empty
  }

  if (props.plan) {
    name.value        = props.plan.name
    baseRateXof.value = props.plan.baseRateXof
    minNights.value   = props.plan.minNights
    validFrom.value   = props.plan.validFrom?.slice(0, 10) ?? ''
    validTo.value     = props.plan.validTo?.slice(0, 10) ?? ''
    isActive.value    = props.plan.isActive
    roomTypeId.value  = props.plan.roomType?.id ?? ''
  }
})

async function submit(): Promise<void> {
  if (!name.value.trim() || !baseRateXof.value) {
    formError.value = 'Le nom et le tarif de base sont obligatoires.'
    return
  }

  submitting.value = true
  formError.value  = null

  const payload: Record<string, unknown> = {
    name:        name.value.trim(),
    baseRateXof: baseRateXof.value,
    minNights:   minNights.value,
    isActive:    isActive.value,
    validFrom:   validFrom.value || undefined,
    validTo:     validTo.value || undefined,
    roomTypeId:  roomTypeId.value || null,
  }

  try {
    if (props.plan) {
      await rateService.updatePlan(props.plan.id, payload as Partial<RatePlan>)
    } else {
      await rateService.createPlan(payload as Partial<RatePlan>)
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
          {{ plan ? 'Modifier le plan' : 'Nouveau plan tarifaire' }}
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
          <label class="input-label">Nom *</label>
          <input v-model="name" class="input" placeholder="Tarif Standard" />
        </div>
        <div class="input-wrap">
          <label class="input-label">Type de chambre</label>
          <select v-model="roomTypeId" class="select">
            <option value="">Tous les types</option>
            <option v-for="rt in roomTypes" :key="rt.id" :value="rt.id">{{ rt.name }}</option>
          </select>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Tarif de base (XOF) *</label>
          <input v-model="baseRateXof" class="input" type="text" inputmode="decimal" placeholder="45000.00" />
        </div>
        <div class="input-wrap">
          <label class="input-label">Nuits minimum</label>
          <input v-model.number="minNights" class="input" type="number" min="1" />
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
            {{ plan ? 'Enregistrer' : 'Creer' }}
          </template>
        </button>
      </div>

    </div>
  </div>
</template>
