<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { subscriptionService } from '@/services/subscription.service'
import { useNotificationsStore } from '@/stores/notifications.store'
import { featureLabel } from '@/modules/subscription/feature-labels'
import { formatCurrency } from '@/utils/currency'
import type { Plan, Subscription } from '@/types/entities'

const props = defineProps<{
  plan: Plan
  subscription: Subscription
}>()

const emit = defineEmits<{
  close: []
  upgraded: []
}>()

const router = useRouter()
const notif  = useNotificationsStore()

const submitting = ref(false)
const error      = ref<string | null>(null)

const isTrial = computed(() => props.subscription.status === 'trial')

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('fr-FR', {
    day: '2-digit', month: 'long', year: 'numeric',
  })
}

const nextPeriodStart = computed(() => {
  return new Date().toLocaleDateString('fr-FR', {
    day: '2-digit', month: 'long', year: 'numeric',
  })
})

const nextPeriodEnd = computed(() => {
  const d = new Date()
  d.setMonth(d.getMonth() + 1)
  return d.toLocaleDateString('fr-FR', {
    day: '2-digit', month: 'long', year: 'numeric',
  })
})

async function confirm(): Promise<void> {
  submitting.value = true
  error.value = null
  try {
    const result = await subscriptionService.upgrade(props.plan.id)

    notif.pushUiToast('success', 'Plan mis à jour', `Vous êtes maintenant sur ${result.plan}.`)

    // Si le backend renvoie une URL de checkout (V1 ne le fait pas
    // mais le contrat est prêt), on redirige vers le règlement.
    if (result.checkoutUrl) {
      window.location.href = result.checkoutUrl
      return
    }

    emit('upgraded')
    router.push('/subscription')
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'response' in e) {
      const err = e as { response: { status: number; data: { error?: string; code?: string } } }
      const code = err.response.data?.code
      if (code === 'BUSINESS_RULE') {
        error.value = err.response.data?.error ?? 'Changement impossible dans l\'état courant.'
      } else if (err.response.status === 403) {
        error.value = 'Réservé au manager.'
      } else {
        notif.pushUiToast('alert', "Impossible d'upgrader pour le moment.")
        emit('close')
      }
    } else {
      notif.pushUiToast('alert', "Impossible d'upgrader pour le moment.")
      emit('close')
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
    <div style="background:#fff; border-radius:var(--radius-xl); padding:1.5rem; width:480px; max-width:92vw;">

      <h3 style="font-size:16px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
        <i class="ti ti-arrow-up-right" aria-hidden="true" style="color:var(--pms-teal);"></i>
        Passer au plan {{ plan.name }}
      </h3>
      <p class="t-muted" style="margin-bottom:1rem;">
        Confirmez le changement de plan.
      </p>

      <!-- Récap plan -->
      <div style="background:var(--pms-sand); border-radius:var(--radius-md); padding:14px 16px; margin-bottom:1rem;">
        <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
          <span class="t-muted">Nouveau plan</span>
          <span style="font-weight:500;">{{ plan.name }}</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
          <span class="t-muted">Prix</span>
          <span style="font-weight:500;">{{ formatCurrency(plan.priceXof) }} / mois HT</span>
        </div>
        <div style="display:flex; justify-content:space-between;">
          <span class="t-muted">Limites</span>
          <span style="font-weight:500;">
            {{ plan.maxRooms ?? 'Illimité' }} chambres · {{ plan.maxUsers ?? 'Illimité' }} utilisateurs
          </span>
        </div>
        <div v-if="plan.features.length > 0" style="margin-top:10px;">
          <div class="t-muted" style="margin-bottom:6px;">Fonctionnalités</div>
          <ul style="list-style:none; padding:0; margin:0; display:flex; flex-wrap:wrap; gap:6px;">
            <li
              v-for="f in plan.features"
              :key="f"
              class="badge"
              style="background:var(--pms-teal-light); color:var(--pms-teal-dark);"
            >
              {{ featureLabel(f) }}
            </li>
          </ul>
        </div>
      </div>

      <!-- Message contextuel selon état courant -->
      <div v-if="isTrial" style="font-size:13px; color:var(--pms-ink-2); margin-bottom:1rem;">
        <p style="margin:0 0 6px;">
          Votre essai sera converti en abonnement actif. La première
          période courra du <strong>{{ nextPeriodStart }}</strong>
          au <strong>{{ nextPeriodEnd }}</strong>.
        </p>
        <p style="margin:0; color:var(--pms-ink-3); font-size:12px;">
          Vous recevrez une facture par email à régler dans les 7 jours.
        </p>
      </div>
      <div v-else style="font-size:13px; color:var(--pms-ink-2); margin-bottom:1rem;">
        <p style="margin:0 0 6px;">
          Le changement prend effet immédiatement. Votre période courante
          (jusqu'au <strong>{{ formatDate(subscription.currentPeriodEnd) }}</strong>)
          n'est pas affectée.
        </p>
        <p style="margin:0; color:var(--pms-ink-3); font-size:12px;">
          Le nouveau plan sera facturé au prochain renouvellement.
        </p>
      </div>

      <!-- Erreur -->
      <div v-if="error"
           style="background:var(--pms-red-light); color:var(--pms-red);
                  padding:8px 12px; border-radius:var(--radius-md);
                  font-size:12px; margin-bottom:10px;">
        <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ error }}
      </div>

      <!-- Actions -->
      <div style="display:flex; gap:8px; justify-content:flex-end;">
        <button class="btn btn-ghost btn-sm" :disabled="submitting" @click="emit('close')">
          Annuler
        </button>
        <button class="btn btn-primary btn-sm" :disabled="submitting" @click="confirm()">
          <span v-if="submitting" class="spinner" style="width:14px; height:14px; border-width:1.5px;"></span>
          <template v-else>Confirmer</template>
        </button>
      </div>
    </div>
  </div>
</template>
