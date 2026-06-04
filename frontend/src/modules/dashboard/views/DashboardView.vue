<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useDashboardStore } from '@/stores/dashboard.store'
import { useAuthStore } from '@/stores/auth.store'
import { formatCurrency } from '@/utils/currency'
import OccupancyChart from '@/modules/dashboard/components/OccupancyChart.vue'
import RevenueChart from '@/modules/dashboard/components/RevenueChart.vue'
import SoldNightsChart from '@/modules/dashboard/components/SoldNightsChart.vue'

const store  = useDashboardStore()
const auth   = useAuthStore()
const router = useRouter()

const canSeeReports         = computed(() => auth.hasFeature('advanced_reports'))
const canManageSubscription = computed(() => auth.canAccess('subscription'))

function gotoPricing(): void {
  router.push('/subscription/pricing')
}

const todayLabel = computed(() => {
  const d = new Date()
  return d.toLocaleDateString('fr-SN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
})

// ── Rapports : sélection de période ──

const reportFrom = ref('')
const reportTo   = ref('')

function setShortcut(days: number): void {
  const to   = new Date()
  const from = new Date()
  from.setDate(from.getDate() - days + 1)
  reportFrom.value = from.toISOString().slice(0, 10)
  reportTo.value   = to.toISOString().slice(0, 10)
}

function setThisMonth(): void {
  const now = new Date()
  reportFrom.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
  reportTo.value   = now.toISOString().slice(0, 10)
}

function generateReport(): void {
  if (reportFrom.value && reportTo.value) {
    store.fetchReport(reportFrom.value, reportTo.value)
  }
}

function exportCsv(): void {
  if (reportFrom.value && reportTo.value) {
    store.exportReport(reportFrom.value, reportTo.value, 'csv')
  }
}

onMounted(() => {
  store.fetchToday()
  store.subscribeLive()
  // Pré-remplir avec les 30 derniers jours
  setShortcut(30)
})

onUnmounted(() => {
  store.unsubscribeLive()
})
</script>

<template>
  <div style="padding:1.5rem; max-width:1100px; margin:0 auto;">

    <!-- ══════════════════════════════════════════════════════ -->
    <!--  Section : Aujourd'hui                                 -->
    <!-- ══════════════════════════════════════════════════════ -->

    <div style="margin-bottom:2rem;">
      <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
        Tableau de bord
      </h1>
      <p class="t-muted" style="font-size:13px;">Activité du jour — {{ todayLabel }}</p>
    </div>

    <!-- Loading -->
    <div v-if="store.loadingToday" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>

    <!-- Error -->
    <div v-else-if="store.error && !store.todayKpis" class="empty-state">
      <i class="ti ti-alert-circle"></i>
      <div>{{ store.error }}</div>
      <button class="btn btn-secondary btn-sm" @click="store.fetchToday()">Réessayer</button>
    </div>

    <template v-else-if="store.todayKpis">
      <!-- Stat cards -->
      <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:12px; margin-bottom:1.5rem;">

        <!-- Taux d'occupation -->
        <div class="stat-card">
          <div class="stat-label">Occupation (vendu)</div>
          <div class="stat-value">{{ store.todayKpis.occupancyRate }}%</div>
          <div class="t-muted" style="font-size:12px; margin-top:4px;">
            {{ store.todayKpis.roomNightsSold }} / {{ store.todayKpis.roomNightsAvailable }} chambres vendues
          </div>
        </div>

        <!-- Occupées -->
        <div class="stat-card">
          <div class="stat-label">Occupées</div>
          <div class="stat-value">{{ store.todayKpis.occupiedRooms }}</div>
          <div class="t-muted" style="font-size:12px; margin-top:4px;">
            / {{ store.todayKpis.availableRooms }} — clients présents
          </div>
        </div>

        <!-- CA HT -->
        <div class="stat-card">
          <div class="stat-label">CA du jour HT</div>
          <div class="stat-value" style="font-size:20px;">{{ formatCurrency(store.todayKpis.roomRevenueHt) }}</div>
        </div>

        <!-- ADR -->
        <div class="stat-card">
          <div class="stat-label">ADR HT</div>
          <div class="stat-value" style="font-size:20px;">{{ formatCurrency(store.todayKpis.adrHt) }}</div>
        </div>

        <!-- RevPAR -->
        <div class="stat-card">
          <div class="stat-label">RevPAR HT</div>
          <div class="stat-value" style="font-size:20px;">{{ formatCurrency(store.todayKpis.revparHt) }}</div>
        </div>

        <!-- Arrivées -->
        <div class="stat-card">
          <div class="stat-label">Arrivées</div>
          <div class="stat-value">{{ store.todayKpis.arrivalsToday }}</div>
        </div>

        <!-- Départs -->
        <div class="stat-card">
          <div class="stat-label">Départs</div>
          <div class="stat-value">{{ store.todayKpis.departuresToday }}</div>
        </div>

      </div>
    </template>

    <!-- ══════════════════════════════════════════════════════ -->
    <!--  Section : Rapports (feature advanced_reports)         -->
    <!-- ══════════════════════════════════════════════════════ -->

    <!-- Bandeau upgrade si pas la feature -->
    <div v-if="!canSeeReports" class="card" style="padding:1.25rem; text-align:center;">
      <i class="ti ti-chart-bar" style="font-size:24px; color:var(--pms-ink-3);"></i>
      <p style="margin:8px 0 4px; font-size:14px; font-weight:500; color:var(--pms-ink);">
        Rapports détaillés
      </p>
      <p class="t-muted" style="font-size:13px; margin-bottom:12px;">
        Occupation, revenus et RevPAR sur période — disponible avec le plan Pro.
      </p>
      <button
        v-if="canManageSubscription"
        class="btn btn-gold btn-sm"
        @click="gotoPricing()"
      >
        <i class="ti ti-star" aria-hidden="true"></i> Passer en Pro
      </button>
      <p
        v-else
        class="t-muted"
        style="font-size:12px; margin-top:8px;"
      >
        Contactez le manager de votre hôtel pour souscrire au plan Pro.
      </p>
    </div>

    <!-- Rapports disponibles -->
    <template v-if="canSeeReports">
      <div style="border-top:0.5px solid var(--pms-border); padding-top:1.5rem; margin-top:0.5rem;">

        <h2 style="font-size:18px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
          Rapports
        </h2>
        <p v-if="store.report && !store.loadingReport" class="t-muted" style="font-size:13px; margin-bottom:12px;">
          Période du {{ store.report.from }} au {{ store.report.to }}
        </p>
        <div v-else style="margin-bottom:12px;"></div>

        <!-- Sélection période -->
        <div class="card" style="padding:1.25rem; margin-bottom:1rem;">
          <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;">
            <div class="input-wrap">
              <span class="input-label">Du</span>
              <input v-model="reportFrom" class="input" type="date" />
            </div>
            <div class="input-wrap">
              <span class="input-label">Au</span>
              <input v-model="reportTo" class="input" type="date" />
            </div>
            <button class="btn btn-primary btn-sm" :disabled="!reportFrom || !reportTo || store.loadingReport" @click="generateReport()">
              <i class="ti ti-chart-bar"></i>
              {{ store.loadingReport ? 'Chargement...' : 'Générer' }}
            </button>
            <button
              v-if="store.report"
              class="btn btn-secondary btn-sm"
              @click="exportCsv()"
            >
              <i class="ti ti-download"></i> Exporter CSV
            </button>
          </div>
          <!-- Raccourcis -->
          <div style="display:flex; gap:6px; margin-top:10px;">
            <button class="btn btn-ghost btn-sm" @click="setShortcut(7)">7 jours</button>
            <button class="btn btn-ghost btn-sm" @click="setShortcut(30)">30 jours</button>
            <button class="btn btn-ghost btn-sm" @click="setThisMonth()">Ce mois</button>
          </div>
        </div>

        <!-- Loading rapport -->
        <div v-if="store.loadingReport" style="display:flex; justify-content:center; padding:3rem 0;">
          <div class="spinner"></div>
        </div>

        <!-- Rapport chargé -->
        <template v-if="store.report && !store.loadingReport">

          <!-- Agrégats période -->
          <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:12px; margin-bottom:1.5rem;">
            <div class="stat-card">
              <div class="stat-label">Occupation moyenne</div>
              <div class="stat-value">{{ store.report.occupancyRate }}%</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">CA HT période</div>
              <div class="stat-value" style="font-size:20px;">{{ formatCurrency(store.report.roomRevenueHt) }}</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">ADR HT</div>
              <div class="stat-value" style="font-size:20px;">{{ formatCurrency(store.report.adrHt) }}</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">RevPAR HT</div>
              <div class="stat-value" style="font-size:20px;">{{ formatCurrency(store.report.revparHt) }}</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">Nuits vendues</div>
              <div class="stat-value">{{ store.report.roomNightsSold }}</div>
              <div class="t-muted" style="font-size:12px; margin-top:4px;">
                / {{ store.report.roomNightsAvailable }} disponibles
              </div>
            </div>
          </div>

          <!-- Graphiques -->
          <div style="display:grid; grid-template-columns:1fr; gap:1rem;">
            <div class="card" style="padding:1.25rem;">
              <div class="stat-label" style="margin-bottom:12px;">Taux d'occupation par jour</div>
              <OccupancyChart :daily-series="store.report.dailySeries" />
            </div>
            <div class="card" style="padding:1.25rem;">
              <div class="stat-label" style="margin-bottom:12px;">Chiffre d'affaires HT par jour</div>
              <RevenueChart :daily-series="store.report.dailySeries" />
            </div>
            <div class="card" style="padding:1.25rem;">
              <div class="stat-label" style="margin-bottom:12px;">Nuits vendues par jour</div>
              <SoldNightsChart :daily-series="store.report.dailySeries" />
            </div>
          </div>

        </template>

      </div>
    </template>

  </div>
</template>
