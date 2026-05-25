<script setup lang="ts">
import { ref, onMounted } from 'vue'
import type { RoomType } from '@/types/entities'
import { roomService } from '@/services/room.service'

const emit = defineEmits<{
  close: []
  saved: []
}>()

const props = defineProps<{
  roomType: RoomType
}>()

const name         = ref('')
const baseRateXof  = ref('')
const maxOccupancy = ref(2)
const description  = ref('')

const submitting = ref(false)
const formError  = ref<string | null>(null)

onMounted(() => {
  name.value         = props.roomType.name
  baseRateXof.value  = props.roomType.baseRateXof
  maxOccupancy.value = props.roomType.maxOccupancy
  description.value  = props.roomType.description ?? ''
})

async function submit(): Promise<void> {
  if (!name.value.trim()) {
    formError.value = 'Le nom est obligatoire.'
    return
  }
  if (!baseRateXof.value || Number(baseRateXof.value) <= 0) {
    formError.value = 'Le tarif de base doit etre superieur a zero.'
    return
  }

  submitting.value = true
  formError.value  = null

  try {
    await roomService.updateType(props.roomType.id, {
      name:         name.value.trim(),
      baseRateXof:  baseRateXof.value,
      maxOccupancy: maxOccupancy.value,
      description:  description.value || null,
    })
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
          Modifier le type de chambre
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
        <input v-model="name" class="input" placeholder="Standard" />
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Tarif de base (XOF) *</label>
          <input v-model="baseRateXof" class="input" type="text" inputmode="decimal" placeholder="45000.00" />
        </div>
        <div class="input-wrap">
          <label class="input-label">Capacite max</label>
          <input v-model.number="maxOccupancy" class="input" type="number" min="1" />
        </div>
      </div>

      <div class="input-wrap" style="margin-bottom:1.5rem;">
        <label class="input-label">Description (optionnel)</label>
        <textarea v-model="description" class="input" style="height:72px; padding:10px 14px; resize:vertical;" placeholder="Description du type de chambre..."></textarea>
      </div>

      <div style="display:flex; gap:8px; justify-content:flex-end;">
        <button class="btn btn-ghost" @click="emit('close')">Annuler</button>
        <button class="btn btn-primary" :disabled="submitting" @click="submit()">
          <span v-if="submitting" class="spinner" style="width:14px; height:14px; border-width:1.5px;"></span>
          <template v-else>
            <i class="ti ti-check" aria-hidden="true"></i>
            Enregistrer
          </template>
        </button>
      </div>

    </div>
  </div>
</template>
