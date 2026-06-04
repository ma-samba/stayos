<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { subscriptionService } from '@/services/subscription.service'
import { featureLabel } from '@/modules/subscription/feature-labels'
import { formatCurrency } from '@/utils/currency'
import UpgradeModal from '@/modules/subscription/components/UpgradeModal.vue'
import type { Plan, Subscription } from '@/types/entities'

const router = useRouter()

const plans         = ref<Plan[]>([])
const subscription  = ref<Subscription | null>(null)
const loading       = ref(true)
const error         = ref<string | null>(null)

const selectedPlan  = ref<Plan | null>(null)

async function fetchAll(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [p, s] = await Promise.all([
      subscriptionService.getPlans(),
      subscriptionService.getCurrent(),
    ])
    plans.value = p
    subscription.value = s
  } catch {
    error.value = 'Impossible de charger les plans'
  } finally {
    loading.value = false
  }
}

const currentPlanId = computed(() => subscription.value?.plan.id ?? null)

// "Recommandé" hardcodé : le plan de tarif le plus élevé qui n'est
// pas ENTERPRISE — la décision UX standard du marché SaaS PME.
const recommendedPlanId = computed(() => {
  const candidates = plans.value.filter(p => p.name !== 'ENTERPRISE')
  if (candidates.length === 0) return null
  return candidates.reduce(
    (best, p) => (Number(p.priceXof) > Number(best.priceXof) ? p : best),
    candidates[0],
  ).id
})

function isCurrent(plan: Plan): boolean {
  return plan.id === currentPlanId.value
}

function isRecommended(plan: Plan): boolean {
  return plan.id === recommendedPlanId.value
}

function openUpgrade(plan: Plan): void {
  if (isCurrent(plan)) return
  if (!subscription.value) return
  selectedPlan.value = plan
}

function onUpgraded(): void {
  selectedPlan.value = null
  // SubscriptionView se recharge à l'arrivée — pas besoin de refetch ici.
  router.push('/subscription')
}

function backToSubscription(): void {
  router.push('/subscription')
}

onMounted(fetchAll)
</script>

<template>
  <div style="padding:1.5rem; max-width:1200px; margin:0 auto;">

    <!-- ── En-tête + retour ── -->
    <button class="btn btn-ghost btn-sm" style="margin-bottom:0.75rem;" @click="backToSubscription()">
      <i class="ti ti-arrow-left" aria-hidden="true"></i>
      Mon abonnement
    </button>

    <div style="margin-bottom:1.5rem;">
      <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
        Choisir un plan
      </h1>
      <p class="t-muted">Tous les prix HT, en FCFA</p>
    </div>

    <!-- ── Toggle Mensuel / Annuel ── -->
    <div class="cycle-toggle">
      <button class="cycle-btn active">Mensuel</button>
      <button class="cycle-btn" disabled>
        Annuel
        <span class="badge" style="background:var(--pms-gold-light); color:var(--pms-gold-dark); margin-left:6px;">
          Bientôt
        </span>
      </button>
    </div>

    <!-- ── Chargement / erreur ── -->
    <div v-if="loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>
    <div v-else-if="error" class="empty-state">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <div>{{ error }}</div>
      <button class="btn btn-secondary btn-sm" @click="fetchAll()">Réessayer</button>
    </div>

    <!-- ── Grille de plans ── -->
    <div
      v-else
      class="plans-grid"
      :style="{ gridTemplateColumns: `repeat(${Math.min(plans.length, 3)}, minmax(0, 1fr))` }"
    >
      <div
        v-for="plan in plans"
        :key="plan.id"
        :class="['plan-card', { recommended: isRecommended(plan), current: isCurrent(plan) }]"
      >
        <div v-if="isRecommended(plan)" class="plan-badge">Recommandé</div>

        <div class="plan-name">{{ plan.name }}</div>

        <div class="plan-price">
          <span class="plan-amount">{{ formatCurrency(plan.priceXof) }}</span>
          <span class="plan-period">/ mois HT</span>
        </div>

        <div class="plan-limits">
          <div>
            <i class="ti ti-bed" aria-hidden="true"></i>
            Jusqu'à <strong>{{ plan.maxRooms ?? 'illimité' }}</strong> chambres
          </div>
          <div>
            <i class="ti ti-users" aria-hidden="true"></i>
            Jusqu'à <strong>{{ plan.maxUsers ?? 'illimité' }}</strong> utilisateurs
          </div>
        </div>

        <ul class="plan-features">
          <li v-for="f in plan.features" :key="f">
            <i class="ti ti-circle-check" aria-hidden="true"></i>
            {{ featureLabel(f) }}
          </li>
          <li v-if="plan.features.length === 0" class="plan-features-empty">
            Fonctionnalités de base
          </li>
        </ul>

        <button
          :class="['btn', 'plan-cta', isCurrent(plan) ? 'btn-secondary' : 'btn-primary']"
          :disabled="isCurrent(plan)"
          @click="openUpgrade(plan)"
        >
          <template v-if="isCurrent(plan)">Plan actuel</template>
          <template v-else>Passer à {{ plan.name }}</template>
        </button>
      </div>
    </div>

    <!-- ── Modal d'upgrade ── -->
    <UpgradeModal
      v-if="selectedPlan && subscription"
      :plan="selectedPlan"
      :subscription="subscription"
      @close="selectedPlan = null"
      @upgraded="onUpgraded()"
    />

  </div>
</template>

<style scoped>
.cycle-toggle {
  display: inline-flex;
  background: var(--pms-sand);
  padding: 4px;
  border-radius: var(--radius-md);
  margin-bottom: 1.5rem;
}
.cycle-btn {
  border: none;
  background: transparent;
  padding: 6px 16px;
  font-size: 13px;
  font-weight: 500;
  color: var(--pms-ink-3);
  cursor: pointer;
  border-radius: var(--radius-sm);
  display: inline-flex;
  align-items: center;
}
.cycle-btn.active {
  background: #fff;
  color: var(--pms-ink);
  box-shadow: 0 1px 2px rgba(26,23,20,0.06);
}
.cycle-btn[disabled] { cursor: not-allowed; opacity: 0.7; }

.plans-grid {
  display: grid;
  gap: 1.25rem;
}
@media (max-width: 900px) {
  .plans-grid { grid-template-columns: 1fr !important; }
}

.plan-card {
  position: relative;
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
}
.plan-card.recommended {
  border: 2px solid var(--pms-gold);
}
.plan-card.current {
  background: var(--pms-sand);
  border-color: var(--pms-border-2);
}

.plan-badge {
  position: absolute;
  top: -10px;
  right: 16px;
  background: var(--pms-gold);
  color: #fff;
  padding: 3px 10px;
  border-radius: 100px;
  font-size: 11px;
  font-weight: 500;
}

.plan-name {
  font-size: 18px;
  font-weight: 500;
  color: var(--pms-ink);
  margin-bottom: 12px;
}

.plan-price { margin-bottom: 1rem; }
.plan-amount {
  font-size: 26px;
  font-weight: 500;
  color: var(--pms-ink);
}
.plan-period {
  font-size: 13px;
  color: var(--pms-ink-3);
  margin-left: 4px;
}

.plan-limits {
  font-size: 13px;
  color: var(--pms-ink-2);
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 1rem;
  padding-bottom: 1rem;
  border-bottom: 0.5px dashed var(--pms-border);
}
.plan-limits i {
  margin-right: 6px;
  color: var(--pms-ink-3);
}

.plan-features {
  list-style: none;
  padding: 0;
  margin: 0 0 1.5rem;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.plan-features li {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--pms-ink-2);
}
.plan-features i { color: var(--pms-green); font-size: 16px; }
.plan-features-empty {
  color: var(--pms-ink-3) !important;
  font-style: italic;
}

.plan-cta {
  width: 100%;
  justify-content: center;
}
</style>
