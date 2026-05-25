<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth.store'
import { rateService } from '@/services/rate.service'
import { roomService } from '@/services/room.service'
import { formatCurrency } from '@/utils/currency'
import type { RatePlan, SeasonalRate, Promotion, RoomType } from '@/types/entities'
import RatePlanForm from '../components/RatePlanForm.vue'
import SeasonalRateForm from '../components/SeasonalRateForm.vue'
import PromotionForm from '../components/PromotionForm.vue'
import RoomTypeForm from '../components/RoomTypeForm.vue'

const auth = useAuthStore()
const canWrite = auth.hasFeature('revenue_management')

// ── Tabs ──

type Tab = 'roomTypes' | 'plans' | 'seasonal' | 'promos'
const activeTab = ref<Tab>('roomTypes')

// ── State ──

const roomTypes  = ref<RoomType[]>([])
const plans      = ref<RatePlan[]>([])
const seasonals  = ref<SeasonalRate[]>([])
const promotions = ref<Promotion[]>([])
const loading    = ref(true)
const error      = ref<string | null>(null)

// ── Modals ──

const showRoomTypeForm = ref(false)
const editingRoomType  = ref<RoomType | null>(null)
const showPlanForm     = ref(false)
const editingPlan      = ref<RatePlan | null>(null)
const showSeasonalForm = ref(false)
const editingSeasonal  = ref<SeasonalRate | null>(null)
const showPromoForm    = ref(false)
const editingPromo     = ref<Promotion | null>(null)

// ── Fetch ──

async function fetchAll(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [rt, p, s, pr] = await Promise.all([
      roomService.getTypes(),
      rateService.listPlans(),
      rateService.listSeasonal(),
      rateService.listPromotions(),
    ])
    roomTypes.value  = rt
    plans.value      = p
    seasonals.value  = s
    promotions.value = pr
  } catch {
    error.value = 'Impossible de charger les tarifs'
  } finally {
    loading.value = false
  }
}

// ── Room Type actions ──

function openRoomTypeForm(rt: RoomType): void {
  editingRoomType.value  = rt
  showRoomTypeForm.value = true
}

function onRoomTypeSaved(): void {
  showRoomTypeForm.value = false
  editingRoomType.value  = null
  fetchAll()
}

// ── Plan actions ──

function openPlanForm(plan?: RatePlan): void {
  editingPlan.value  = plan ?? null
  showPlanForm.value = true
}

function onPlanSaved(): void {
  showPlanForm.value = false
  editingPlan.value  = null
  fetchAll()
}

async function deactivatePlan(plan: RatePlan): Promise<void> {
  await rateService.deletePlan(plan.id)
  fetchAll()
}

function openSeasonalForm(rate?: SeasonalRate): void {
  editingSeasonal.value  = rate ?? null
  showSeasonalForm.value = true
}

function onSeasonalSaved(): void {
  showSeasonalForm.value = false
  editingSeasonal.value  = null
  fetchAll()
}

async function deactivateSeasonal(rate: SeasonalRate): Promise<void> {
  await rateService.deleteSeasonal(rate.id)
  fetchAll()
}

function openPromoForm(promo?: Promotion): void {
  editingPromo.value  = promo ?? null
  showPromoForm.value = true
}

function onPromoSaved(): void {
  showPromoForm.value = false
  editingPromo.value  = null
  fetchAll()
}

async function deactivatePromo(promo: Promotion): Promise<void> {
  await rateService.deletePromotion(promo.id)
  fetchAll()
}

onMounted(fetchAll)
</script>

<template>
  <div style="padding:2rem;">

    <!-- Header -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
      <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink);">Tarifs</h1>
    </div>

    <!-- Feature flag banner (only on revenue_management tabs) -->
    <div v-if="!canWrite && activeTab !== 'roomTypes'" class="card-sand" style="display:flex; align-items:center; gap:12px; padding:1rem 1.25rem; margin-bottom:1.5rem; border:0.5px solid var(--pms-gold-light);">
      <i class="ti ti-star" style="font-size:20px; color:var(--pms-gold);"></i>
      <div>
        <div style="font-size:13px; font-weight:500; color:var(--pms-ink);">Fonctionnalite Plan Pro</div>
        <div style="font-size:12px; color:var(--pms-ink-3);">
          La gestion des tarifs avances est disponible avec le plan Pro. Vous pouvez consulter les tarifs existants.
        </div>
      </div>
      <button class="btn btn-gold btn-sm" style="margin-left:auto; white-space:nowrap;">
        <i class="ti ti-star" aria-hidden="true"></i> Passer en Pro
      </button>
    </div>

    <!-- Tabs -->
    <div class="tabs" style="margin-bottom:1.5rem;">
      <button :class="['tab', activeTab === 'roomTypes' ? 'active' : '']" @click="activeTab = 'roomTypes'">
        Types de chambre
      </button>
      <button :class="['tab', activeTab === 'plans' ? 'active' : '']" @click="activeTab = 'plans'">
        Plans tarifaires
      </button>
      <button :class="['tab', activeTab === 'seasonal' ? 'active' : '']" @click="activeTab = 'seasonal'">
        Tarifs saisonniers
      </button>
      <button :class="['tab', activeTab === 'promos' ? 'active' : '']" @click="activeTab = 'promos'">
        Codes promo
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" style="text-align:center; padding:3rem; color:var(--pms-ink-3);">
      <span class="spinner" style="width:24px; height:24px; border-width:2px; margin:0 auto 1rem;"></span>
      <div>Chargement...</div>
    </div>

    <!-- Error -->
    <div v-else-if="error" style="background:var(--pms-red-light); color:var(--pms-red); padding:1rem; border-radius:var(--radius-md); font-size:13px;">
      <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ error }}
    </div>

    <!-- ── Types de chambre ── -->
    <template v-else-if="activeTab === 'roomTypes'">
      <div v-if="roomTypes.length === 0" class="empty-state" style="text-align:center; padding:3rem;">
        <i class="ti ti-bed-off" style="font-size:32px; color:var(--pms-ink-3);"></i>
        <div style="font-size:14px; font-weight:500; color:var(--pms-ink-2); margin-top:0.5rem;">Aucun type de chambre</div>
      </div>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Nom</th>
              <th>Tarif de base</th>
              <th>Capacite max</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="rt in roomTypes" :key="rt.id">
              <td style="font-weight:500;">{{ rt.name }}</td>
              <td>{{ formatCurrency(rt.baseRateXof) }}</td>
              <td>{{ rt.maxOccupancy }} pers.</td>
              <td style="text-align:right;">
                <button class="btn btn-ghost btn-icon-sm" @click="openRoomTypeForm(rt)">
                  <i class="ti ti-edit" aria-hidden="true"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── Plans tarifaires ── -->
    <template v-else-if="activeTab === 'plans'">
      <div style="display:flex; justify-content:flex-end; margin-bottom:1rem;">
        <button class="btn btn-primary btn-sm" :disabled="!canWrite" @click="openPlanForm()">
          <i class="ti ti-plus" aria-hidden="true"></i> Nouveau plan
        </button>
      </div>

      <div v-if="plans.length === 0" class="empty-state" style="text-align:center; padding:3rem;">
        <i class="ti ti-receipt-off" style="font-size:32px; color:var(--pms-ink-3);"></i>
        <div style="font-size:14px; font-weight:500; color:var(--pms-ink-2); margin-top:0.5rem;">Aucun plan tarifaire</div>
      </div>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Nom</th>
              <th>Type</th>
              <th>Tarif de base</th>
              <th>Nuits min</th>
              <th>Validite</th>
              <th>Statut</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="plan in plans" :key="plan.id">
              <td style="font-weight:500;">{{ plan.name }}</td>
              <td class="t-muted" style="font-size:12px;">{{ plan.roomType?.name ?? 'Tous' }}</td>
              <td>{{ formatCurrency(plan.baseRateXof) }}</td>
              <td>{{ plan.minNights }}</td>
              <td class="t-muted" style="font-size:12px;">
                <template v-if="plan.validFrom || plan.validTo">
                  {{ plan.validFrom?.slice(0, 10) ?? '...' }} - {{ plan.validTo?.slice(0, 10) ?? '...' }}
                </template>
                <template v-else>Permanent</template>
              </td>
              <td>
                <span :class="['badge', plan.isActive ? 'badge-available' : '']" :style="!plan.isActive ? 'background:var(--pms-sand-2); color:var(--pms-ink-3);' : ''">
                  <span class="badge-dot" :style="plan.isActive ? '' : 'background:var(--pms-ink-3);'"></span>
                  {{ plan.isActive ? 'Actif' : 'Inactif' }}
                </span>
              </td>
              <td style="text-align:right;">
                <button v-if="canWrite" class="btn btn-ghost btn-icon-sm" @click="openPlanForm(plan)">
                  <i class="ti ti-edit" aria-hidden="true"></i>
                </button>
                <button v-if="canWrite && plan.isActive" class="btn btn-ghost btn-icon-sm" style="color:var(--pms-red);" @click="deactivatePlan(plan)">
                  <i class="ti ti-trash" aria-hidden="true"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── Tarifs saisonniers ── -->
    <template v-else-if="activeTab === 'seasonal'">
      <div style="display:flex; justify-content:flex-end; margin-bottom:1rem;">
        <button class="btn btn-primary btn-sm" :disabled="!canWrite" @click="openSeasonalForm()">
          <i class="ti ti-plus" aria-hidden="true"></i> Nouveau tarif
        </button>
      </div>

      <div v-if="seasonals.length === 0" class="empty-state" style="text-align:center; padding:3rem;">
        <i class="ti ti-sun-off" style="font-size:32px; color:var(--pms-ink-3);"></i>
        <div style="font-size:14px; font-weight:500; color:var(--pms-ink-2); margin-top:0.5rem;">Aucun tarif saisonnier</div>
      </div>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Nom</th>
              <th>Type</th>
              <th>Valeur</th>
              <th>Periode</th>
              <th>Priorite</th>
              <th>Statut</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="rate in seasonals" :key="rate.id">
              <td style="font-weight:500;">{{ rate.name }}</td>
              <td>
                <span class="badge" style="background:var(--pms-blue-light); color:var(--pms-blue);">
                  {{ rate.type === 'multiplier' ? 'Multiplicateur' : 'Absolu' }}
                </span>
              </td>
              <td>
                {{ rate.type === 'multiplier' ? `x${rate.value}` : formatCurrency(rate.value) }}
              </td>
              <td class="t-muted" style="font-size:12px;">
                {{ rate.startDate.slice(0, 10) }} - {{ rate.endDate.slice(0, 10) }}
              </td>
              <td>{{ rate.priority }}</td>
              <td>
                <span :class="['badge', rate.isActive ? 'badge-available' : '']" :style="!rate.isActive ? 'background:var(--pms-sand-2); color:var(--pms-ink-3);' : ''">
                  <span class="badge-dot" :style="rate.isActive ? '' : 'background:var(--pms-ink-3);'"></span>
                  {{ rate.isActive ? 'Actif' : 'Inactif' }}
                </span>
              </td>
              <td style="text-align:right;">
                <button v-if="canWrite" class="btn btn-ghost btn-icon-sm" @click="openSeasonalForm(rate)">
                  <i class="ti ti-edit" aria-hidden="true"></i>
                </button>
                <button v-if="canWrite && rate.isActive" class="btn btn-ghost btn-icon-sm" style="color:var(--pms-red);" @click="deactivateSeasonal(rate)">
                  <i class="ti ti-trash" aria-hidden="true"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── Codes promo ── -->
    <template v-else-if="activeTab === 'promos'">
      <div style="display:flex; justify-content:flex-end; margin-bottom:1rem;">
        <button class="btn btn-primary btn-sm" :disabled="!canWrite" @click="openPromoForm()">
          <i class="ti ti-plus" aria-hidden="true"></i> Nouveau code promo
        </button>
      </div>

      <div v-if="promotions.length === 0" class="empty-state" style="text-align:center; padding:3rem;">
        <i class="ti ti-discount-off" style="font-size:32px; color:var(--pms-ink-3);"></i>
        <div style="font-size:14px; font-weight:500; color:var(--pms-ink-2); margin-top:0.5rem;">Aucun code promo</div>
      </div>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Type</th>
              <th>Valeur</th>
              <th>Nuits min</th>
              <th>Validite</th>
              <th>Statut</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="promo in promotions" :key="promo.id">
              <td><span class="t-mono" style="font-weight:500;">{{ promo.code }}</span></td>
              <td>
                <span class="badge" style="background:var(--pms-gold-light); color:var(--pms-gold-dark);">
                  {{ promo.type === 'percentage' ? 'Pourcentage' : 'Montant fixe' }}
                </span>
              </td>
              <td>
                {{ promo.type === 'percentage' ? `${promo.value}%` : formatCurrency(promo.value) }}
              </td>
              <td>{{ promo.minNights }}</td>
              <td class="t-muted" style="font-size:12px;">
                <template v-if="promo.validFrom || promo.validTo">
                  {{ promo.validFrom?.slice(0, 10) ?? '...' }} - {{ promo.validTo?.slice(0, 10) ?? '...' }}
                </template>
                <template v-else>Permanent</template>
              </td>
              <td>
                <span :class="['badge', promo.isActive ? 'badge-available' : '']" :style="!promo.isActive ? 'background:var(--pms-sand-2); color:var(--pms-ink-3);' : ''">
                  <span class="badge-dot" :style="promo.isActive ? '' : 'background:var(--pms-ink-3);'"></span>
                  {{ promo.isActive ? 'Actif' : 'Inactif' }}
                </span>
              </td>
              <td style="text-align:right;">
                <button v-if="canWrite" class="btn btn-ghost btn-icon-sm" @click="openPromoForm(promo)">
                  <i class="ti ti-edit" aria-hidden="true"></i>
                </button>
                <button v-if="canWrite && promo.isActive" class="btn btn-ghost btn-icon-sm" style="color:var(--pms-red);" @click="deactivatePromo(promo)">
                  <i class="ti ti-trash" aria-hidden="true"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── Modals ── -->
    <RoomTypeForm
      v-if="showRoomTypeForm && editingRoomType"
      :room-type="editingRoomType"
      @close="showRoomTypeForm = false"
      @saved="onRoomTypeSaved()"
    />
    <RatePlanForm
      v-if="showPlanForm"
      :plan="editingPlan"
      @close="showPlanForm = false"
      @saved="onPlanSaved()"
    />
    <SeasonalRateForm
      v-if="showSeasonalForm"
      :rate="editingSeasonal"
      @close="showSeasonalForm = false"
      @saved="onSeasonalSaved()"
    />
    <PromotionForm
      v-if="showPromoForm"
      :promotion="editingPromo"
      @close="showPromoForm = false"
      @saved="onPromoSaved()"
    />
  </div>
</template>

<style scoped>
.tabs {
  display: flex;
  gap: 0;
  border-bottom: 0.5px solid var(--pms-border);
}
.tab {
  padding: 10px 18px;
  background: none;
  border: none;
  font-family: var(--font);
  font-size: 13px;
  font-weight: 400;
  color: var(--pms-ink-3);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: all 0.15s;
}
.tab:hover {
  color: var(--pms-ink-2);
}
.tab.active {
  color: var(--pms-ink);
  font-weight: 500;
  border-bottom-color: var(--pms-ink);
}
</style>
