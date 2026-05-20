<script setup lang="ts">
import { ref } from 'vue'
import { useReservationsStore } from '@/stores/reservations.store'

const props = defineProps<{
  reservationId: string
}>()

const emit = defineEmits<{
  close: []
  done: []
}>()

const store      = useReservationsStore()
const notes      = ref('')
const submitting = ref(false)
const error      = ref<string | null>(null)

async function confirm(): Promise<void> {
  submitting.value = true
  error.value = null
  try {
    await store.checkIn(props.reservationId, notes.value || undefined)
    emit('done')
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'response' in e) {
      const err = e as { response: { data: { error: string } } }
      error.value = err.response?.data?.error ?? 'Erreur lors du check-in'
    } else {
      error.value = 'Erreur lors du check-in'
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
    <div style="background:#fff; border-radius:var(--radius-xl); padding:1.5rem; width:400px; max-width:90vw;">
      <div style="margin-bottom:1rem;">
        <h3 style="font-size:16px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
          <i class="ti ti-login" aria-hidden="true" style="color:var(--pms-teal);"></i>
          Confirmer le check-in
        </h3>
        <p class="t-muted">La chambre sera marquée comme occupée.</p>
      </div>

      <div class="input-wrap" style="margin-bottom:1rem;">
        <label class="input-label">Note (optionnel)</label>
        <input v-model="notes" class="input" placeholder="Ex: Client VIP, arrivée tardive..." />
      </div>

      <div v-if="error"
           style="background:var(--pms-red-light); color:var(--pms-red);
                  padding:8px 12px; border-radius:var(--radius-md);
                  font-size:12px; margin-bottom:10px;">
        <i class="ti ti-alert-circle"></i> {{ error }}
      </div>

      <div style="display:flex; gap:8px; justify-content:flex-end;">
        <button class="btn btn-ghost btn-sm" @click="emit('close')">Annuler</button>
        <button class="btn btn-primary btn-sm" :disabled="submitting" @click="confirm()">
          <span v-if="submitting" class="spinner" style="width:14px; height:14px; border-width:1.5px;"></span>
          <template v-else>
            <i class="ti ti-login" aria-hidden="true"></i> Enregistrer l'arrivée
          </template>
        </button>
      </div>
    </div>
  </div>
</template>
