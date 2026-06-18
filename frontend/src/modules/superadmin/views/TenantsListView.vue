<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { superadminService } from '@/services/superadmin.service'
import type {
  PlatformMetrics,
  TenantSummary,
  TenantsListMeta,
  TenantStatus,
} from '@/types/superadmin'

const router = useRouter()

const tenants  = ref<TenantSummary[]>([])
const meta     = ref<TenantsListMeta>({ total: 0, page: 1, perPage: 20, pages: 0 })
const metrics  = ref<PlatformMetrics | null>(null)
const loading  = ref(false)
const error    = ref<string | null>(null)

const search   = ref('')
const status   = ref<'' | TenantStatus>('')
const plan     = ref<'' | 'STARTER' | 'PRO' | 'ENTERPRISE'>('')
const page     = ref(1)
const perPage  = ref(20)

let searchTimer: number | null = null

async function fetchTenants(): Promise<void> {
  loading.value = true
  error.value   = null
  try {
    const params: Record<string, string | number> = {
      page: page.value,
      perPage: perPage.value,
    }
    if (status.value) params.status = status.value
    if (plan.value)   params.plan   = plan.value
    if (search.value.trim()) params.search = search.value.trim()

    const response = await superadminService.listTenants(params)
    tenants.value = response.data
    meta.value    = response.meta
  } catch (e: unknown) {
    error.value = 'Impossible de charger la liste des tenants.'
    console.error(e)
  } finally {
    loading.value = false
  }
}

async function fetchMetrics(): Promise<void> {
  try {
    metrics.value = await superadminService.getMetrics()
  } catch {
    /* silencieux — les chiffres en haut sont accessoires */
  }
}

watch(search, () => {
  if (searchTimer) window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => {
    page.value = 1
    fetchTenants()
  }, 300)
})

watch([status, plan, perPage], () => {
  page.value = 1
  fetchTenants()
})

watch(page, () => fetchTenants())

function gotoDetail(slug: string): void {
  router.push(`/superadmin/tenants/${slug}`)
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('fr-SN', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

const STATUS_LABEL: Record<TenantStatus, string> = {
  active:    'Actif',
  trial:     'Essai',
  suspended: 'Suspendu',
  churned:   'Désabonné',
}

function statusClass(s: string): string {
  return `sa-badge sa-badge--${s}`
}

onMounted(() => {
  fetchTenants()
  fetchMetrics()
})
</script>

<template>
  <div class="sa-page">
    <header class="sa-page-head">
      <div>
        <h1>Tenants</h1>
        <p class="t-muted">Tous les hôtels enregistrés sur la plateforme.</p>
      </div>
      <button
        class="btn btn-primary btn-sm"
        @click="router.push('/superadmin/tenants/new')"
      >
        <i class="ti ti-building-plus"></i> Nouveau tenant
      </button>
    </header>

    <!-- Métriques en haut -->
    <div v-if="metrics" class="sa-stats">
      <div class="stat-card">
        <div class="stat-label">Total</div>
        <div class="stat-value">{{ metrics.activeTenantsCount + metrics.trialTenantsCount + metrics.suspendedTenantsCount + metrics.churnedTenantsCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Actifs</div>
        <div class="stat-value" style="color:var(--pms-green);">{{ metrics.activeTenantsCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Essai</div>
        <div class="stat-value" style="color:var(--pms-gold-dark);">{{ metrics.trialTenantsCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Suspendus</div>
        <div class="stat-value" style="color:var(--pms-red);">{{ metrics.suspendedTenantsCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Résiliés</div>
        <div class="stat-value" style="color:var(--pms-ink-3);">{{ metrics.churnedTenantsCount }}</div>
      </div>
    </div>

    <!-- Filtres -->
    <div class="sa-filters">
      <div class="sa-filter-search">
        <i class="ti ti-search" aria-hidden="true"></i>
        <input
          v-model="search"
          class="input"
          type="search"
          placeholder="Rechercher par slug ou nom…"
          autocomplete="off"
        />
      </div>
      <select v-model="status" class="input sa-select">
        <option value="">Tous statuts</option>
        <option value="active">Actif</option>
        <option value="trial">Essai</option>
        <option value="suspended">Suspendu</option>
        <option value="churned">Désabonné</option>
      </select>
      <select v-model="plan" class="input sa-select">
        <option value="">Tous plans</option>
        <option value="STARTER">Starter</option>
        <option value="PRO">Pro</option>
        <option value="ENTERPRISE">Enterprise</option>
      </select>
      <select v-model.number="perPage" class="input sa-select sa-select-sm">
        <option :value="10">10 / page</option>
        <option :value="20">20 / page</option>
        <option :value="50">50 / page</option>
        <option :value="100">100 / page</option>
      </select>
    </div>

    <!-- Tableau -->
    <div v-if="error" class="sa-error">
      <i class="ti ti-alert-circle"></i> {{ error }}
    </div>

    <div v-else-if="loading && tenants.length === 0" class="sa-loading">
      Chargement…
    </div>

    <div v-else-if="tenants.length === 0" class="sa-empty">
      <i class="ti ti-search-off"></i>
      <p>Aucun tenant ne correspond à ces filtres.</p>
    </div>

    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Slug</th>
            <th>Nom</th>
            <th>Statut</th>
            <th>Plan</th>
            <th>Abonnement</th>
            <th>Créé le</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="t in tenants"
            :key="t.id"
            class="sa-row"
            @click="gotoDetail(t.slug)"
          >
            <td><span class="t-mono">{{ t.slug }}</span></td>
            <td>{{ t.name }}</td>
            <td>
              <span :class="statusClass(t.status)">
                <span class="sa-dot"></span>
                {{ STATUS_LABEL[t.status] ?? t.status }}
              </span>
            </td>
            <td>{{ t.planName ?? '—' }}</td>
            <td>{{ t.subscriptionStatus ?? '—' }}</td>
            <td>{{ formatDate(t.createdAt) }}</td>
            <td class="sa-actions-cell">
              <button class="btn btn-ghost btn-sm" @click.stop="gotoDetail(t.slug)">
                Voir <i class="ti ti-arrow-right"></i>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="meta.pages > 1" class="sa-pagination">
      <button
        class="btn btn-ghost btn-sm"
        :disabled="page <= 1"
        @click="page--"
      >
        <i class="ti ti-chevron-left"></i> Précédent
      </button>
      <span class="sa-page-info">
        Page {{ meta.page }} sur {{ meta.pages }} — {{ meta.total }} tenants
      </span>
      <button
        class="btn btn-ghost btn-sm"
        :disabled="page >= meta.pages"
        @click="page++"
      >
        Suivant <i class="ti ti-chevron-right"></i>
      </button>
    </div>
  </div>
</template>

<style scoped>
.sa-page {
  padding: 1.5rem;
  max-width: 1400px;
  margin: 0 auto;
}

.sa-page-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
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
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 12px;
  margin-bottom: 1.5rem;
}
.stat-card {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-md);
  padding: 1.1rem 1.25rem;
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
}

.sa-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 1.25rem;
  align-items: center;
}
.sa-filter-search {
  position: relative;
  flex: 1;
  min-width: 240px;
}
.sa-filter-search i {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 16px;
  color: var(--pms-ink-3);
  pointer-events: none;
}
.sa-filter-search .input {
  padding-left: 36px;
}
.input {
  height: 38px;
  padding: 0 14px;
  border: 0.5px solid var(--pms-border-2);
  border-radius: var(--radius-md);
  font-family: var(--font);
  font-size: 13px;
  background: #fff;
  color: var(--pms-ink);
  width: 100%;
}
.sa-select  { width: 160px; }
.sa-select-sm { width: 130px; }

.sa-row { cursor: pointer; }
.sa-row:hover td { background: #faf9f7; }

.sa-actions-cell { text-align: right; white-space: nowrap; }

.table-wrap {
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  background: #fff;
}
table { width: 100%; border-collapse: collapse; }
thead tr { background: var(--pms-sand); }
th {
  font-size: 11px;
  font-weight: 500;
  color: var(--pms-ink-3);
  text-align: left;
  padding: 11px 16px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
td {
  font-size: 13px;
  color: var(--pms-ink-2);
  padding: 11px 16px;
  border-top: 0.5px solid var(--pms-border);
}

.t-mono {
  font-family: var(--mono);
  font-size: 12px;
  color: var(--pms-teal);
}

.sa-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: 100px;
  font-size: 11px;
  font-weight: 500;
}
.sa-dot { width: 5px; height: 5px; border-radius: 50%; }
.sa-badge--active     { background: var(--pms-green-light); color: var(--pms-green); }
.sa-badge--active .sa-dot     { background: var(--pms-green); }
.sa-badge--trial      { background: var(--pms-gold-light); color: var(--pms-gold-dark); }
.sa-badge--trial .sa-dot      { background: var(--pms-gold); }
.sa-badge--suspended  { background: var(--pms-red-light); color: var(--pms-red); }
.sa-badge--suspended .sa-dot  { background: var(--pms-red); }
.sa-badge--churned    { background: rgba(26,23,20,0.08); color: var(--pms-ink-3); }
.sa-badge--churned .sa-dot    { background: var(--pms-ink-3); }

.sa-loading,
.sa-empty {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  padding: 3rem;
  text-align: center;
  color: var(--pms-ink-3);
  font-size: 14px;
}
.sa-empty i { font-size: 32px; display: block; margin: 0 auto 12px; }

.sa-error {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--pms-red-light);
  color: var(--pms-red);
  padding: 12px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
  margin-bottom: 1rem;
}

.sa-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-top: 1rem;
  padding: 0.75rem;
}
.sa-page-info {
  font-size: 12px;
  color: var(--pms-ink-3);
}
</style>
