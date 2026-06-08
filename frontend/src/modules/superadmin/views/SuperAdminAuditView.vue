<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { superadminService } from '@/services/superadmin.service'
import type {
  SuperAdminAuditEntry,
  SuperAdminAuditMeta,
} from '@/types/superadmin'

const entries = ref<SuperAdminAuditEntry[]>([])
const meta    = ref<SuperAdminAuditMeta>({ total: 0, page: 1, perPage: 20, pages: 0 })
const loading = ref(false)
const error   = ref<string | null>(null)

const actor       = ref('')
const tenantSlug  = ref('')
const action      = ref('')
const page        = ref(1)
const perPage     = ref(20)

const expanded = ref<Set<string>>(new Set())

const ACTION_LABEL: Record<string, string> = {
  'tenant.created':          'Tenant créé',
  'tenant.updated':          'Tenant modifié',
  'tenant.suspended':        'Tenant suspendu',
  'tenant.reactivated':      'Tenant réactivé',
  'subscription.force_plan': 'Plan forcé',
}

const ACTION_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '',                       label: 'Toutes actions' },
  { value: 'tenant.created',         label: 'Tenant créé' },
  { value: 'tenant.updated',         label: 'Tenant modifié' },
  { value: 'tenant.suspended',       label: 'Tenant suspendu' },
  { value: 'tenant.reactivated',     label: 'Tenant réactivé' },
  { value: 'subscription.force_plan', label: 'Plan forcé' },
]

let searchTimer: number | null = null

async function load(): Promise<void> {
  loading.value = true
  error.value   = null
  try {
    const params: Record<string, string | number> = {
      page: page.value,
      per_page: perPage.value,
    }
    if (actor.value.trim())      params.actor       = actor.value.trim()
    if (tenantSlug.value.trim()) params.tenant_slug = tenantSlug.value.trim()
    if (action.value)            params.action      = action.value

    const response = await superadminService.listAudit(params)
    entries.value = response.data
    meta.value    = response.meta
  } catch (e) {
    error.value = "Impossible de charger l'audit."
    console.error(e)
  } finally {
    loading.value = false
  }
}

watch([actor, tenantSlug], () => {
  if (searchTimer) window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})

watch([action, perPage], () => {
  page.value = 1
  load()
})

watch(page, () => load())

function toggleExpand(id: string): void {
  if (expanded.value.has(id)) expanded.value.delete(id)
  else expanded.value.add(id)
}

function actionLabel(value: string): string {
  return ACTION_LABEL[value] ?? value
}

function actionColor(value: string): string {
  if (value === 'tenant.created' || value === 'tenant.reactivated') return 'green'
  if (value === 'tenant.suspended') return 'red'
  if (value === 'tenant.updated' || value === 'subscription.force_plan') return 'gold'
  return 'ink'
}

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('fr-SN', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

function payloadSummary(payload: Record<string, unknown> | null): string {
  if (!payload) return '—'
  const keys = Object.keys(payload)
  if (keys.length === 0) return '—'

  // Affichage rapide : reason et plan en priorité s'ils existent
  const parts: string[] = []
  if (payload.reason)   parts.push(`raison : ${String(payload.reason)}`)
  if (payload.planTo)   parts.push(`→ ${String(payload.planTo)}`)
  if (payload.plan)     parts.push(`plan ${String(payload.plan)}`)
  if (payload.slug && !payload.planTo) parts.push(`slug ${String(payload.slug)}`)
  if (parts.length > 0) return parts.join(' · ')

  return `${keys.length} champ(s)`
}

onMounted(load)
</script>

<template>
  <div class="sa-page">
    <header class="sa-page-head">
      <h1>Journal d'audit</h1>
      <p class="t-muted">
        Actions sensibles effectuées par les SuperAdmins. Lecture seule.
      </p>
    </header>

    <!-- Filtres -->
    <div class="sa-filters">
      <input
        v-model="actor"
        class="input"
        type="text"
        placeholder="Email de l'acteur"
      />
      <input
        v-model="tenantSlug"
        class="input"
        type="text"
        placeholder="Slug tenant"
      />
      <select v-model="action" class="input">
        <option v-for="opt in ACTION_OPTIONS" :key="opt.value" :value="opt.value">
          {{ opt.label }}
        </option>
      </select>
      <select v-model.number="perPage" class="input sa-select-sm">
        <option :value="20">20 / page</option>
        <option :value="50">50 / page</option>
        <option :value="100">100 / page</option>
      </select>
    </div>

    <div v-if="error" class="sa-error">
      <i class="ti ti-alert-circle"></i> {{ error }}
    </div>

    <div v-else-if="loading && entries.length === 0" class="sa-loading">
      Chargement…
    </div>

    <div v-else-if="entries.length === 0" class="sa-empty">
      <i class="ti ti-search-off"></i>
      <p>Aucune entrée pour ces filtres.</p>
    </div>

    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Acteur</th>
            <th>Tenant</th>
            <th>Action</th>
            <th>Détail</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="entry in entries" :key="entry.id">
            <tr class="sa-row" @click="toggleExpand(entry.id)">
              <td>{{ formatDateTime(entry.createdAt) }}</td>
              <td>{{ entry.actorEmail }}</td>
              <td>
                <span v-if="entry.tenantSlug" class="t-mono">{{ entry.tenantSlug }}</span>
                <span v-else class="sa-muted">—</span>
              </td>
              <td>
                <span :class="['sa-pill', `sa-pill--${actionColor(entry.action)}`]">
                  {{ actionLabel(entry.action) }}
                </span>
              </td>
              <td class="sa-summary">{{ payloadSummary(entry.payload) }}</td>
              <td>
                <span v-if="entry.ipAddress" class="t-mono">{{ entry.ipAddress }}</span>
                <span v-else class="sa-muted">—</span>
              </td>
            </tr>
            <tr v-if="expanded.has(entry.id)" class="sa-detail-row">
              <td colspan="6">
                <div class="sa-detail-grid">
                  <div>
                    <div class="sa-detail-label">Payload</div>
                    <pre class="sa-payload">{{ entry.payload ? JSON.stringify(entry.payload, null, 2) : '—' }}</pre>
                  </div>
                  <div>
                    <div class="sa-detail-label">User-Agent</div>
                    <pre class="sa-payload">{{ entry.userAgent ?? '—' }}</pre>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="meta.pages > 1" class="sa-pagination">
      <button class="btn btn-ghost btn-sm" :disabled="page <= 1" @click="page--">
        <i class="ti ti-chevron-left"></i> Précédent
      </button>
      <span class="sa-page-info">
        Page {{ meta.page }} sur {{ meta.pages }} — {{ meta.total }} entrées
      </span>
      <button class="btn btn-ghost btn-sm" :disabled="page >= meta.pages" @click="page++">
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

.sa-page-head h1 {
  font-size: 22px;
  font-weight: 500;
  color: var(--pms-ink);
  margin: 0 0 4px;
}
.t-muted { color: var(--pms-ink-3); font-size: 13px; }
.t-mono  { font-family: var(--mono); font-size: 12px; color: var(--pms-teal); }
.sa-muted { color: var(--pms-ink-3); }

.sa-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin: 1.25rem 0;
}
.input {
  height: 38px;
  padding: 0 12px;
  border: 0.5px solid var(--pms-border-2);
  border-radius: var(--radius-md);
  font-family: var(--font);
  font-size: 13px;
  background: #fff;
  flex: 1;
  min-width: 180px;
}
.sa-select-sm { flex: 0 0 130px; }

.sa-loading, .sa-empty {
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
  display: flex; align-items: center; gap: 8px;
  background: var(--pms-red-light);
  color: var(--pms-red);
  padding: 12px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
}

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

.sa-row { cursor: pointer; }
.sa-row:hover td { background: #faf9f7; }

.sa-pill {
  display: inline-flex;
  padding: 3px 10px;
  border-radius: 100px;
  font-size: 11px;
  font-weight: 500;
}
.sa-pill--green { background: var(--pms-green-light); color: var(--pms-green); }
.sa-pill--gold  { background: var(--pms-gold-light);  color: var(--pms-gold-dark); }
.sa-pill--red   { background: var(--pms-red-light);   color: var(--pms-red); }
.sa-pill--ink   { background: rgba(26,23,20,0.08);    color: var(--pms-ink-3); }

.sa-summary {
  font-size: 12px;
  color: var(--pms-ink-3);
}

.sa-detail-row td {
  background: var(--pms-sand-2);
  padding: 16px;
}
.sa-detail-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 1rem;
}
.sa-detail-label {
  font-size: 11px;
  color: var(--pms-ink-3);
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin-bottom: 6px;
}
.sa-payload {
  font-family: var(--mono);
  font-size: 11px;
  color: var(--pms-ink);
  background: #fff;
  border-radius: var(--radius-md);
  padding: 10px;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 240px;
  overflow-y: auto;
  margin: 0;
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
