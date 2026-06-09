<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationsStore } from '@/stores/notifications.store'
import { nightAuditService } from '@/services/night-audit.service'
import type {
  NightAuditCurrent,
  NightAuditChecklist,
  DailyCloseListResponse,
} from '@/types/night-audit'
import WarningList from '../components/WarningList.vue'
import ConfirmCloseModal from '../components/ConfirmCloseModal.vue'

// ──────────────────────────────────────────────────────────────
//  NightAuditView — Sprint 13quater-C
//  Vue principale réceptionniste : statut + checklist + bouton
//  clôturer + historique paginé.
// ──────────────────────────────────────────────────────────────

const router = useRouter()
const notif  = useNotificationsStore()

const current   = ref<NightAuditCurrent | null>(null)
const checklist = ref<NightAuditChecklist | null>(null)
const history   = ref<DailyCloseListResponse | null>(null)
const loading   = ref(true)

const page      = ref(1)
const perPage   = 20
const submitting = ref(false)
const showConfirmModal = ref(false)

const warnings = computed(() => checklist.value?.warnings ?? [])

const lastEffectiveClose = computed(() => {
  // L'historique le plus récent (la liste est triée business_date DESC).
  return history.value?.data?.[0] ?? null
})

async function reload(): Promise<void> {
  loading.value = true
  try {
    const [cur, chk, hist] = await Promise.all([
      nightAuditService.getCurrent(),
      nightAuditService.getChecklist(),
      nightAuditService.list(page.value, perPage),
    ])
    current.value   = cur
    checklist.value = chk
    history.value   = hist
  } catch (e) {
    notif.pushUiToast('alert', extractError(e, 'Chargement impossible.'))
  } finally {
    loading.value = false
  }
}

async function changePage(newPage: number): Promise<void> {
  if (newPage < 1 || newPage > (history.value?.meta.pages ?? 1)) return
  page.value = newPage
  history.value = await nightAuditService.list(page.value, perPage)
}

async function onConfirmClose(force: boolean): Promise<void> {
  submitting.value = true
  try {
    const closed = await nightAuditService.close(force)
    notif.pushUiToast(
      'success',
      force ? 'Journée clôturée (forcée).' : 'Journée clôturée.',
    )
    showConfirmModal.value = false
    // Re-fetch + jump vers le détail pour faciliter l'inspection
    await reload()
    router.push({ name: 'night-audit-detail', params: { id: closed.id } })
  } catch (e) {
    notif.pushUiToast('alert', extractError(e, 'Clôture impossible.'))
  } finally {
    submitting.value = false
  }
}

async function downloadPdf(id: string, businessDate: string): Promise<void> {
  try {
    const dateOnly = businessDate.split('T')[0]
    await nightAuditService.downloadPdf(id, dateOnly)
  } catch (e) {
    notif.pushUiToast('alert', extractError(e, 'Téléchargement PDF impossible.'))
  }
}

function fmtDateBusiness(iso: string | null | undefined): string {
  if (!iso) return '—'
  const datePart = iso.split('T')[0]
  const [y, m, d] = datePart.split('-')
  if (!y || !m || !d) return iso
  return `${d}/${m}/${y}`
}

function fmtDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString('fr-FR', {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    })
  } catch {
    return iso
  }
}

function extractError(e: unknown, fallback: string): string {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const resp = (e as any)?.response?.data
  return resp?.error ?? fallback
}

onMounted(reload)
</script>

<template>
  <div class="na-view">
    <header class="page-header">
      <h1>Clôture journalière</h1>
      <p class="t-muted">Vérifiez et clôturez la journée d'exploitation.</p>
    </header>

    <div v-if="loading" class="loading">Chargement…</div>

    <template v-else-if="current">
      <!-- État courant -->
      <section
        v-if="current.alreadyClosed"
        class="status-card status-done"
      >
        <i class="ti ti-circle-check" aria-hidden="true"></i>
        <div class="status-body">
          <div class="status-title">
            Journée du {{ fmtDateBusiness(current.businessDate) }} déjà clôturée
          </div>
          <div class="status-sub" v-if="lastEffectiveClose">
            Clôturée le {{ fmtDateTime(lastEffectiveClose.closedAt) }}
            par {{ lastEffectiveClose.closedByEmail }}.
          </div>
        </div>
        <button
          v-if="lastEffectiveClose"
          class="btn btn-secondary"
          @click="router.push({ name: 'night-audit-detail', params: { id: lastEffectiveClose.id } })"
        >
          <i class="ti ti-eye" aria-hidden="true"></i>
          Voir le détail
        </button>
      </section>

      <section
        v-else-if="!current.canClose"
        class="status-card status-blocked"
      >
        <i class="ti ti-alert-triangle" aria-hidden="true"></i>
        <div class="status-body">
          <div class="status-title">Clôture impossible</div>
          <div class="status-sub">
            {{ current.reason || 'Une clôture précédente reste à finaliser.' }}
            Contactez votre administrateur pour clôturer les journées passées.
          </div>
        </div>
      </section>

      <section v-else class="status-card status-active">
        <div class="status-body">
          <div class="status-title">
            Journée d'exploitation&nbsp;:
            {{ fmtDateBusiness(current.businessDate) }}
          </div>
          <div class="status-sub">
            Cutoff configuré&nbsp;: 5 h (changement de business date à 5 h du matin)
          </div>
        </div>
      </section>

      <!-- Checklist warnings -->
      <section
        v-if="current.canClose && warnings.length > 0"
        class="warnings-section"
      >
        <header class="section-header">
          <h2>Avertissements ({{ warnings.length }})</h2>
          <p class="t-muted">
            Vous pouvez clôturer en confirmant, mais ces points seront enregistrés
            dans le snapshot.
          </p>
        </header>
        <WarningList :warnings="warnings" />
      </section>

      <!-- Bouton clôturer -->
      <div v-if="current.canClose" class="actions">
        <button
          class="btn"
          :class="warnings.length === 0 ? 'btn-primary' : 'btn-warning'"
          @click="showConfirmModal = true"
        >
          <i class="ti ti-moon-stars" aria-hidden="true"></i>
          {{
            warnings.length === 0
              ? 'Clôturer la journée'
              : `Clôturer malgré ${warnings.length} avertissement(s)`
          }}
        </button>
      </div>

      <!-- Historique -->
      <section class="history-section">
        <header class="section-header">
          <h2>Historique des clôtures</h2>
        </header>

        <table v-if="history && history.data.length > 0" class="data-table">
          <thead>
            <tr>
              <th>Date business</th>
              <th>Clôturée le</th>
              <th>Par</th>
              <th>Statut</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in history.data" :key="c.id">
              <td><strong>{{ fmtDateBusiness(c.businessDate) }}</strong></td>
              <td>{{ fmtDateTime(c.closedAt) }}</td>
              <td>{{ c.closedByEmail }}</td>
              <td>
                <span
                  class="badge"
                  :class="c.reopenedAt ? 'badge-reopened' : 'badge-effective'"
                >
                  {{ c.reopenedAt ? 'Rouverte' : 'Effective' }}
                </span>
              </td>
              <td style="text-align:right;">
                <button
                  class="btn btn-ghost btn-sm"
                  aria-label="Voir le détail"
                  @click="router.push({ name: 'night-audit-detail', params: { id: c.id } })"
                >
                  <i class="ti ti-eye" aria-hidden="true"></i>
                </button>
                <button
                  class="btn btn-ghost btn-sm"
                  aria-label="Télécharger PDF"
                  @click="downloadPdf(c.id, c.businessDate)"
                >
                  <i class="ti ti-download" aria-hidden="true"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="t-muted">Aucune clôture pour l'instant.</p>

        <div v-if="history && history.meta.pages > 1" class="pagination">
          <button
            class="btn btn-ghost btn-sm"
            :disabled="page <= 1"
            @click="changePage(page - 1)"
          >
            <i class="ti ti-chevron-left" aria-hidden="true"></i> Précédent
          </button>
          <span class="page-info">Page {{ page }} / {{ history.meta.pages }}</span>
          <button
            class="btn btn-ghost btn-sm"
            :disabled="page >= history.meta.pages"
            @click="changePage(page + 1)"
          >
            Suivant <i class="ti ti-chevron-right" aria-hidden="true"></i>
          </button>
        </div>
      </section>
    </template>

    <!-- Modal -->
    <ConfirmCloseModal
      :is-open="showConfirmModal"
      :warnings="warnings"
      :submitting="submitting"
      @close="showConfirmModal = false"
      @confirm="onConfirmClose"
    />
  </div>
</template>

<style scoped>
.na-view { padding: 24px; max-width: 1100px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }
.page-header h1 { font-size: 24px; font-weight: 500; margin: 0 0 4px 0; }
.t-muted { color: var(--pms-ink-3); font-size: 13px; margin: 0; }

.loading { padding: 40px 0; text-align: center; color: var(--pms-ink-3); }

.status-card {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 18px 22px;
  border-radius: 14px;
  border: 0.5px solid var(--pms-border);
  background: #fff;
}
.status-card i { font-size: 24px; flex-shrink: 0; }
.status-body { flex: 1; }
.status-title { font-size: 15px; font-weight: 500; color: var(--pms-ink); }
.status-sub { font-size: 12px; color: var(--pms-ink-3); margin-top: 3px; }

.status-done { background: #EAF6EC; border-color: #BBE0C2; }
.status-done i { color: #2E7D4F; }
.status-blocked { background: #FBF6E8; border-color: #E8D5A0; }
.status-blocked i { color: #C4922A; }
.status-active { background: #fff; }

.warnings-section, .history-section { display: flex; flex-direction: column; gap: 10px; }
.section-header h2 { font-size: 16px; font-weight: 500; margin: 0 0 4px 0; }

.actions { display: flex; justify-content: flex-end; }

.data-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border-radius: 12px; overflow: hidden; border: 0.5px solid var(--pms-border); }
.data-table th { text-align: left; padding: 10px 14px; color: var(--pms-ink-3); font-weight: 500; border-bottom: 0.5px solid var(--pms-border); font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; background: var(--pms-sand, #F5F0E8); }
.data-table td { padding: 10px 14px; border-bottom: 0.5px solid var(--pms-border); }
.data-table tr:last-child td { border-bottom: none; }

.badge { display: inline-block; padding: 2px 10px; border-radius: 100px; font-size: 11px; font-weight: 500; }
.badge-effective { background: #D4EDE0; color: #2E7D4F; }
.badge-reopened  { background: #F5DADA; color: #B83232; }

.pagination { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 4px; }
.page-info { font-size: 13px; color: var(--pms-ink-3); }

.btn { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 16px; border-radius: 10px; border: none; font-family: var(--font); font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-sm { height: 30px; padding: 0 10px; font-size: 12px; }
.btn-ghost { background: transparent; color: var(--pms-ink-3); }
.btn-ghost:hover:not(:disabled) { background: var(--pms-sand-2, #EDE7D9); }
.btn-primary { background: var(--pms-ink); color: #fff; }
.btn-warning { background: #C4922A; color: #fff; }
.btn-warning:hover:not(:disabled) { background: #8A6319; }
.btn-secondary { background: #fff; color: var(--pms-ink); border: 0.5px solid var(--pms-border-2); }
</style>
