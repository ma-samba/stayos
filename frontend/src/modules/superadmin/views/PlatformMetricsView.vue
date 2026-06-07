<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  Chart as ChartJS,
  Title, Tooltip, Legend,
  BarElement, CategoryScale, LinearScale,
} from 'chart.js'
import { Bar } from 'vue-chartjs'
import { superadminService } from '@/services/superadmin.service'
import type { PlatformMetrics } from '@/types/superadmin'
import { formatCurrency } from '@/utils/currency'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

const metrics = ref<PlatformMetrics | null>(null)
const loading = ref(true)
const error   = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value   = null
  try {
    metrics.value = await superadminService.getMetrics()
  } catch (e) {
    error.value = 'Impossible de charger les métriques.'
    console.error(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

const totalTenants = computed(() => {
  if (!metrics.value) return 0
  return metrics.value.activeTenantsCount
    + metrics.value.trialTenantsCount
    + metrics.value.suspendedTenantsCount
    + metrics.value.cancelledTenantsCount
})

const planChartData = computed(() => {
  if (!metrics.value) return { labels: [], datasets: [] }
  return {
    labels: ['Starter', 'Pro', 'Enterprise'],
    datasets: [{
      label: 'Abonnements actifs ou en essai',
      data: [
        metrics.value.planDistribution.STARTER,
        metrics.value.planDistribution.PRO,
        metrics.value.planDistribution.ENTERPRISE,
      ],
      backgroundColor: ['#1A1714', '#1D6E6E', '#C4922A'],
      borderRadius: 6,
      maxBarThickness: 60,
    }],
  }
})

const planChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: '#1A1714',
      padding: 10,
      titleFont: { size: 12, weight: 500 as const },
      bodyFont: { size: 12 },
    },
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: { stepSize: 1, precision: 0 },
      grid: { color: 'rgba(26,23,20,0.06)' },
    },
    x: {
      grid: { display: false },
    },
  },
}
</script>

<template>
  <div class="sa-page">
    <header class="sa-page-head">
      <h1>Métriques plateforme</h1>
      <p class="t-muted">Vue d'ensemble en temps réel de l'activité StayOS.</p>
    </header>

    <div v-if="loading" class="sa-loading">Chargement…</div>

    <div v-else-if="error" class="sa-error">
      <i class="ti ti-alert-circle"></i> {{ error }}
    </div>

    <template v-else-if="metrics">
      <!-- KPIs principaux -->
      <div class="sa-stats sa-stats-primary">
        <div class="stat-card stat-card-feature">
          <div class="stat-label">MRR (revenu mensuel récurrent)</div>
          <div class="stat-value">{{ formatCurrency(metrics.mrr) }}</div>
          <div class="stat-hint">Cumul des abonnements actifs</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Tenants actifs</div>
          <div class="stat-value" style="color:var(--pms-green);">{{ metrics.activeTenantsCount }}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">En essai</div>
          <div class="stat-value" style="color:var(--pms-gold-dark);">{{ metrics.trialTenantsCount }}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Suspendus</div>
          <div class="stat-value" style="color:var(--pms-red);">{{ metrics.suspendedTenantsCount }}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Désabonnés</div>
          <div class="stat-value" style="color:var(--pms-ink-3);">{{ metrics.cancelledTenantsCount }}</div>
        </div>
      </div>

      <!-- KPIs secondaires -->
      <div class="sa-stats sa-stats-secondary">
        <div class="stat-card">
          <div class="stat-label">Total tenants</div>
          <div class="stat-value">{{ totalTenants }}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Nouveaux (30 jours)</div>
          <div class="stat-value" style="color:var(--pms-teal);">+{{ metrics.newTenantsLast30Days }}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Churn (30 jours)</div>
          <div class="stat-value" style="color:var(--pms-red);">{{ metrics.churnLast30Days }}</div>
        </div>
      </div>

      <!-- Distribution par plan -->
      <section class="card">
        <h2 class="sa-section-title">Répartition par plan</h2>
        <div class="chart-wrap">
          <Bar :data="planChartData" :options="planChartOptions" />
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.sa-page {
  padding: 1.5rem;
  max-width: 1400px;
  margin: 0 auto;
}

.sa-page-head {
  margin-bottom: 1.5rem;
}
.sa-page-head h1 {
  font-size: 22px;
  font-weight: 500;
  color: var(--pms-ink);
  margin: 0 0 4px;
}
.t-muted {
  color: var(--pms-ink-3);
  font-size: 13px;
}

.sa-stats {
  display: grid;
  gap: 12px;
  margin-bottom: 1.5rem;
}
.sa-stats-primary {
  grid-template-columns: 1.5fr repeat(auto-fit, minmax(170px, 1fr));
}
.sa-stats-secondary {
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.stat-card {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-md);
  padding: 1.1rem 1.25rem;
}
.stat-card-feature {
  background: var(--pms-ink);
  color: #fff;
  border-color: transparent;
}
.stat-card-feature .stat-label {
  color: rgba(255,255,255,0.6);
}
.stat-card-feature .stat-value {
  color: #fff;
}
.stat-label {
  font-size: 11px;
  color: var(--pms-ink-3);
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin-bottom: 8px;
}
.stat-value {
  font-size: 26px;
  font-weight: 500;
  color: var(--pms-ink);
  line-height: 1.1;
}
.stat-hint {
  margin-top: 6px;
  font-size: 11px;
  color: rgba(255,255,255,0.5);
}

.card {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  padding: 1.25rem 1.5rem;
}
.sa-section-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--pms-ink);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin: 0 0 1rem;
}
.chart-wrap {
  height: 300px;
}

.sa-loading {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  padding: 3rem;
  text-align: center;
  color: var(--pms-ink-3);
}

.sa-error {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--pms-red-light);
  color: var(--pms-red);
  padding: 12px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
}
</style>
