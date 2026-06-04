<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import api from '@/services/api.service'

const router = useRouter()
const auth   = useAuthStore()

const canManageSubscription = auth.canAccess('subscription')
const reloading    = ref(false)
const reloadError  = ref<string | null>(null)

function goToSubscription(): void {
  router.push('/subscription')
}

function goToPricing(): void {
  router.push('/subscription/pricing')
}

async function reload(): Promise<void> {
  reloading.value   = true
  reloadError.value = null
  try {
    // Ping un endpoint NON exempté du 402. Si 200 → tenant actif,
    // on sort vers la première route accessible (dashboard pour
    // MANAGER/RECEPTIONIST/ACCOUNTANT, etc.).
    // Si 402, l'intercepteur axios reste sur /account-suspended
    // grâce à sa garde anti-boucle.
    await api.get('/dashboard/today')
    router.push(auth.firstAccessiblePath())
  } catch (error: unknown) {
    const response =
      typeof error === 'object' && error !== null && 'response' in error
        ? (error as { response?: { status: number } }).response
        : undefined

    if (response?.status === 402) {
      reloadError.value =
        'Le compte est toujours suspendu. Si vous venez de '
        + 'régulariser, patientez quelques instants ou contactez le '
        + 'support.'
    } else if (response?.status === 403) {
      // HOUSEKEEPER n'a pas accès à /dashboard mais le 403 prouve
      // que le tenant n'est plus bloqué — on laisse le router
      // diriger vers la première route accessible.
      router.push(auth.firstAccessiblePath())
    } else {
      reloadError.value = 'Erreur lors de la vérification.'
    }
  } finally {
    reloading.value = false
  }
}

function logoutAndRedirect(): void {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <div class="suspended-screen">
    <div class="suspended-card card">
      <div class="suspended-icon">
        <i class="ti ti-shield-x" aria-hidden="true"></i>
      </div>

      <h1 class="suspended-title">Compte suspendu</h1>

      <p class="suspended-lead">
        L'accès à StayOS est temporairement suspendu pour cet hôtel.
        Cela peut être dû à un essai expiré sans souscription, ou à
        une facture en attente de règlement.
      </p>

      <div v-if="canManageSubscription" class="suspended-block">
        <p class="suspended-block-text">
          En tant que manager, vous pouvez régulariser dès maintenant.
        </p>
        <div class="suspended-actions">
          <button class="btn btn-primary" @click="goToSubscription">
            <i class="ti ti-credit-card" aria-hidden="true"></i>
            Voir mon abonnement
          </button>
          <button class="btn btn-secondary" @click="goToPricing">
            <i class="ti ti-list-details" aria-hidden="true"></i>
            Voir les plans
          </button>
        </div>
        <button class="suspended-reload" :disabled="reloading" @click="reload">
          <i class="ti ti-refresh" aria-hidden="true"></i>
          {{ reloading ? 'Vérification…' : "J'ai régularisé, recharger" }}
        </button>
        <p v-if="reloadError" class="suspended-reload-error">
          {{ reloadError }}
        </p>
      </div>

      <div v-else class="suspended-block">
        <p class="suspended-block-text">
          Contactez le manager de votre hôtel pour régulariser la
          situation.
        </p>
        <div class="suspended-actions">
          <button class="btn btn-secondary" @click="logoutAndRedirect">
            <i class="ti ti-logout" aria-hidden="true"></i>
            Se déconnecter
          </button>
        </div>
      </div>

      <a
        class="suspended-help"
        href="mailto:support@stayos.sn?subject=Compte%20suspendu"
      >
        Besoin d'aide&nbsp;? Contacter le support
      </a>
    </div>
  </div>
</template>

<style scoped>
.suspended-screen {
  min-height: 100vh;
  background: var(--pms-sand);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem 1.25rem;
}

.suspended-card {
  max-width: 520px;
  width: 100%;
  padding: 2.5rem 2rem;
  text-align: center;
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
}

.suspended-icon {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--pms-red-light);
  color: var(--pms-red);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1.25rem;
}
.suspended-icon i { font-size: 32px; }

.suspended-title {
  font-size: 22px;
  font-weight: 500;
  color: var(--pms-ink);
  margin: 0 0 0.75rem;
}

.suspended-lead {
  font-size: 14px;
  color: var(--pms-ink-2);
  line-height: 1.55;
  margin: 0 0 1.75rem;
}

.suspended-block {
  background: var(--pms-sand);
  border-radius: var(--radius-md);
  padding: 1.25rem 1.25rem 1.5rem;
  margin-bottom: 1.5rem;
}

.suspended-block-text {
  font-size: 13px;
  color: var(--pms-ink-2);
  margin: 0 0 1rem;
}

.suspended-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
}

.suspended-reload {
  margin-top: 1.25rem;
  background: transparent;
  border: none;
  color: var(--pms-ink-3);
  font-family: var(--font);
  font-size: 12px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.suspended-reload:hover {
  color: var(--pms-ink);
}
.suspended-reload:disabled {
  opacity: 0.5;
  cursor: progress;
}
.suspended-reload i { font-size: 14px; }

.suspended-reload-error {
  margin: 10px 0 0;
  font-size: 12px;
  color: var(--pms-red);
  line-height: 1.4;
}

.suspended-help {
  display: inline-block;
  font-size: 12px;
  color: var(--pms-ink-3);
  text-decoration: none;
}
.suspended-help:hover {
  color: var(--pms-ink-2);
  text-decoration: underline;
}
</style>
