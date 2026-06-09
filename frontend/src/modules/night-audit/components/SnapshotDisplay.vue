<script setup lang="ts">
import { computed } from 'vue'
import type { DailyCloseSnapshot } from '@/types/night-audit'
import WarningList from './WarningList.vue'

// ──────────────────────────────────────────────────────────────
//  SnapshotDisplay — Sprint 13quater-C
//  Rendu lecture seule du snapshot figé d'une clôture.
// ──────────────────────────────────────────────────────────────

const props = defineProps<{
  snapshot: DailyCloseSnapshot
}>()

const kpis = computed(() => props.snapshot.kpis ?? {})
const counts = computed(() => props.snapshot.counts ?? {})
const cash = computed(() => props.snapshot.cash ?? { byMethod: {}, totalXof: '0.00' })
const invoices = computed(() => props.snapshot.invoices ?? { issued: 0, totalXof: '0.00' })
const rooms = computed(() => props.snapshot.rooms ?? [])
const warnings = computed(() => props.snapshot.warnings ?? [])

const cashEntries = computed(() => Object.entries(cash.value.byMethod ?? {}))

const visibleRooms = computed(() => rooms.value.slice(0, 50))
const hiddenRoomsCount = computed(() => Math.max(0, rooms.value.length - 50))

const vatApprox = computed(() => {
  const ttc = Number(invoices.value.totalXof ?? 0)
  if (!Number.isFinite(ttc) || ttc <= 0) return '0.00'
  return (ttc - ttc / 1.18).toFixed(2)
})

function fmtXof(v: string | number | undefined): string {
  if (v === undefined || v === null || v === '') return '—'
  const n = Number(v)
  if (Number.isNaN(n)) return String(v)
  return new Intl.NumberFormat('fr-FR').format(n) + ' XOF'
}

function fmtPercent(v: string | number | undefined): string {
  if (v === undefined || v === null || v === '') return '—'
  const n = Number(v)
  if (Number.isNaN(n)) return String(v)
  return `${n.toFixed(2)} %`
}

function statusLabel(s: string): string {
  return s.replace(/_/g, ' ')
}

function methodLabel(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ')
}
</script>

<template>
  <div class="snapshot">
    <!-- KPIs -->
    <section class="card">
      <h3>KPIs du jour</h3>
      <div class="kpi-grid">
        <div class="kpi">
          <div class="kpi-label">Taux d'occupation</div>
          <div class="kpi-value">{{ fmtPercent(kpis.occupancyRate) }}</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">ADR HT</div>
          <div class="kpi-value">{{ fmtXof(kpis.adrHtXof) }}</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">RevPAR HT</div>
          <div class="kpi-value">{{ fmtXof(kpis.revparHtXof) }}</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">CA TTC</div>
          <div class="kpi-value">{{ fmtXof(kpis.revenueTtcXof) }}</div>
        </div>
      </div>
    </section>

    <!-- Activité -->
    <section class="card">
      <h3>Activité du jour</h3>
      <table class="kv">
        <tbody>
          <tr><td class="label">Arrivées prévues</td><td class="value">{{ counts.arrivals ?? 0 }}</td></tr>
          <tr><td class="label">Départs prévus</td><td class="value">{{ counts.departures ?? 0 }}</td></tr>
          <tr>
            <td class="label">Chambres occupées / disponibles</td>
            <td class="value">{{ kpis.occupiedRooms ?? 0 }} / {{ kpis.availableRooms ?? 0 }}</td>
          </tr>
          <tr>
            <td class="label">Nuits vendues / disponibles</td>
            <td class="value">{{ kpis.soldNights ?? 0 }} / {{ kpis.availableNights ?? 0 }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- Caisse -->
    <section class="card">
      <h3>Caisse encaissée</h3>
      <p v-if="cashEntries.length === 0" class="t-muted">Aucun paiement enregistré.</p>
      <table v-else class="kv">
        <tbody>
          <tr v-for="[method, amount] in cashEntries" :key="method">
            <td class="label">{{ methodLabel(method) }}</td>
            <td class="value">{{ fmtXof(amount) }}</td>
          </tr>
          <tr class="total-row">
            <td class="label">Total encaissé</td>
            <td class="value">{{ fmtXof(cash.totalXof) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- Factures -->
    <section class="card">
      <h3>Factures émises</h3>
      <table class="kv">
        <tbody>
          <tr><td class="label">Nombre de factures émises</td><td class="value">{{ invoices.issued }}</td></tr>
          <tr><td class="label">Total TTC</td><td class="value">{{ fmtXof(invoices.totalXof) }}</td></tr>
          <tr><td class="label">Dont TVA (18%) ≈</td><td class="value">{{ fmtXof(vatApprox) }}</td></tr>
        </tbody>
      </table>
    </section>

    <!-- État des chambres -->
    <section class="card">
      <h3>État des chambres (instant T)</h3>
      <p v-if="rooms.length === 0" class="t-muted">Aucune chambre.</p>
      <template v-else>
        <table class="data-table">
          <thead>
            <tr><th>N°</th><th>Statut</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in visibleRooms" :key="r.id">
              <td>{{ r.number }}</td>
              <td>
                <span class="badge" :class="`badge-${r.status}`">{{ statusLabel(r.status) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="hiddenRoomsCount > 0" class="t-muted" style="margin-top: 8px;">
          … et {{ hiddenRoomsCount }} autre(s) chambre(s) non affichée(s).
        </p>
      </template>
    </section>

    <!-- Warnings figés -->
    <section v-if="warnings.length > 0" class="card">
      <h3>Avertissements figés au moment de la clôture</h3>
      <WarningList :warnings="warnings" dense />
    </section>
  </div>
</template>

<style scoped>
.snapshot { display: flex; flex-direction: column; gap: 16px; }

.card { background: #fff; border: 0.5px solid var(--pms-border); border-radius: 16px; padding: 20px 24px; }
.card h3 { font-size: 14px; font-weight: 500; margin: 0 0 14px 0; color: var(--pms-ink); }

.t-muted { color: var(--pms-ink-3); font-size: 13px; font-style: italic; }

.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
}
@media (max-width: 720px) {
  .kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
.kpi {
  background: var(--pms-sand, #F5F0E8);
  border-radius: 10px;
  padding: 14px 16px;
}
.kpi-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--pms-ink-3);
  font-weight: 500;
}
.kpi-value {
  font-size: 18px;
  font-weight: 500;
  color: var(--pms-ink);
  margin-top: 4px;
}

.kv { width: 100%; border-collapse: collapse; }
.kv td { padding: 8px 0; font-size: 13px; border-bottom: 0.5px solid var(--pms-border); }
.kv td.label { color: var(--pms-ink-3); }
.kv td.value { text-align: right; font-weight: 500; }
.kv tr.total-row td { font-weight: 600; border-top: 2px solid var(--pms-ink); padding-top: 12px; }
.kv tr:last-child td { border-bottom: none; }

.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table th { text-align: left; padding: 8px 10px; color: var(--pms-ink-3); font-weight: 500; border-bottom: 0.5px solid var(--pms-border); font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
.data-table td { padding: 8px 10px; border-bottom: 0.5px solid var(--pms-border); }

.badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 100px;
  font-size: 11px;
  font-weight: 500;
  background: var(--pms-sand-2, #EDE7D9);
  color: var(--pms-ink);
}
.badge-available    { background: #D4EDE0; color: #2E7D4F; }
.badge-occupied     { background: #F5DADA; color: #B83232; }
.badge-cleaning     { background: #F5E6C8; color: #8A6319; }
.badge-maintenance  { background: #D4E2F5; color: #2B5BA8; }
.badge-out_of_order { background: #DCDCDC; color: #555; }
.badge-checked_in   { background: #D4EDED; color: #0D4444; }
</style>
