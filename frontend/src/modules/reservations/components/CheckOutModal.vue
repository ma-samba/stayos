<script setup lang="ts">
import { ref } from 'vue'
import { useReservationsStore } from '@/stores/reservations.store'
import { formatCurrency } from '@/utils/currency'

const props = defineProps<{
  reservationId: string
  total?: string
}>()

const emit = defineEmits<{
  close: []
  done: []
}>()

const store      = useReservationsStore()
const submitting = ref(false)
const error      = ref<string | null>(null)

async function confirm(): Promise<void> {
  submitting.value = true
  error.value = null
  try {
    await store.checkOut(props.reservationId)
    emit('done')
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'response' in e) {
      const err = e as { response: { data: { error: string } } }
      error.value = err.response?.data?.error ?? 'Erreur lors du check-out'
    } else {
      error.value = 'Erreur lors du check-out'
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
          <i class="ti ti-logout" aria-hidden="true" style="color:var(--pms-gold);"></i>
          Confirmer le check-out
        </h3>
        <p class="t-muted">La chambre sera marquée en ménage. Le nombre de séjours du client sera incrémenté.</p>
      </div>

      <div v-if="props.total" class="card-sand" style="padding:12px; border-radius:var(--radius-md); margin-bottom:1rem;">
        <div style="display:flex; justify-content:space-between; font-size:15px; font-weight:500;">
          <span>Total séjour</span>
          <span>{{ formatCurrency(props.total) }}</span>
        </div>
        <div class="t-muted" style="font-size:12px; margin-top:4px;">
          Une facture brouillon sera générée automatiquement.
        </div>
      </div>

      <div
v-if="error"
           style="background:var(--pms-red-light); color:var(--pms-red);
                  padding:8px 12px; border-radius:var(--radius-md);
                  font-size:12px; margin-bottom:10px;"
>
        <i class="ti ti-alert-circle"></i> {{ error }}
      </div>

      <div style="display:flex; gap:8px; justify-content:flex-end;">
        <button class="btn btn-ghost btn-sm" @click="emit('close')">Annuler</button>
        <button class="btn btn-primary btn-sm" :disabled="submitting" @click="confirm()">
          <span v-if="submitting" class="spinner" style="width:14px; height:14px; border-width:1.5px;"></span>
          <template v-else>
            <i class="ti ti-logout" aria-hidden="true"></i> Enregistrer le départ
          </template>
        </button>
      </div>
    </div>
  </div>
</template>
