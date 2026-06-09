<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { useNotificationsStore } from '@/stores/notifications.store'
import { nightAuditService } from '@/services/night-audit.service'
import type { DailyClose } from '@/types/night-audit'
import SnapshotDisplay from '../components/SnapshotDisplay.vue'
import ReopenModal from '../components/ReopenModal.vue'

// ──────────────────────────────────────────────────────────────
//  NightAuditDetailView — Sprint 13quater-C
//  Vue détail d'une clôture : header + bannières + actions + snapshot.
// ──────────────────────────────────────────────────────────────

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const notif  = useNotificationsStore()

const close = ref<DailyClose | null>(null)
const loading = ref(true)
const showReopenModal = ref(false)
const submitting = ref(false)

const isManager = computed(() => auth.userRole === 'MANAGER')
const isReopened = computed(() => close.value?.reopenedAt !== null && close.value?.reopenedAt !== undefined)
const hasWarnings = computed(() => (close.value?.snapshot?.warnings?.length ?? 0) > 0)

async function load(): Promise<void> {
  loading.value = true
  try {
    close.value = await nightAuditService.getOne(String(route.params.id))
  } catch (e) {
    notif.pushUiToast('alert', extractError(e, 'Clôture introuvable.'))
    router.push({ name: 'night-audit' })
  } finally {
    loading.value = false
  }
}

async function onDownloadPdf(): Promise<void> {
  if (!close.value) return
  try {
    const dateOnly = close.value.businessDate.split('T')[0]
    await nightAuditService.downloadPdf(close.value.id, dateOnly)
  } catch (e) {
    notif.pushUiToast('alert', extractError(e, 'Téléchargement PDF impossible.'))
  }
}

async function onConfirmReopen(reason: string): Promise<void> {
  if (!close.value) return
  submitting.value = true
  try {
    const updated = await nightAuditService.reopen(close.value.id, reason)
    close.value = updated
    showReopenModal.value = false
    notif.pushUiToast('success', 'Clôture rouverte.')
  } catch (e) {
    // 403 si l'utilisateur n'est pas manager (sécurité côté serveur)
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const status = (e as any)?.response?.status
    if (status === 403) {
      notif.pushUiToast('alert', 'Seul un manager peut rouvrir une clôture.')
    } else {
      notif.pushUiToast('alert', extractError(e, 'Réouverture impossible.'))
    }
  } finally {
    submitting.value = false
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

onMounted(load)
</script>

<template>
  <div class="detail-view">
    <header class="page-header">
      <button class="back-link" @click="router.push({ name: 'night-audit' })">
        <i class="ti ti-chevron-left" aria-hidden="true"></i> Historique
      </button>

      <div v-if="close" class="title-row">
        <div>
          <h1>Clôture du {{ fmtDateBusiness(close.businessDate) }}</h1>
          <p class="t-muted">
            Clôturée le {{ fmtDateTime(close.closedAt) }} par {{ close.closedByEmail }}.
          </p>
        </div>
        <span class="badge" :class="isReopened ? 'badge-reopened' : 'badge-effective'">
          {{ isReopened ? 'Rouverte' : 'Effective' }}
        </span>
      </div>
    </header>

    <div v-if="loading" class="loading">Chargement…</div>

    <template v-else-if="close">
      <!-- Bannière rouverte -->
      <div v-if="isReopened" class="banner banner-reopened">
        <i class="ti ti-alert-circle" aria-hidden="true"></i>
        <div>
          <strong>Cette clôture a été rouverte</strong>
          le {{ fmtDateTime(close.reopenedAt) }} par {{ close.reopenedByEmail }}.
          <em v-if="close.reopenReason">Raison : "{{ close.reopenReason }}"</em>
        </div>
      </div>

      <!-- Bannière forcée -->
      <div v-if="hasWarnings" class="banner banner-forced">
        <i class="ti ti-alert-triangle" aria-hidden="true"></i>
        <div>
          <strong>CLÔTURE FORCÉE</strong> avec
          {{ close.snapshot.warnings.length }} avertissement(s) — détaillés en bas de page.
        </div>
      </div>

      <!-- Actions -->
      <div class="actions">
        <button class="btn btn-secondary" @click="onDownloadPdf">
          <i class="ti ti-download" aria-hidden="true"></i> Télécharger PDF
        </button>
        <button
          v-if="isManager && !isReopened"
          class="btn btn-warning"
          @click="showReopenModal = true"
        >
          <i class="ti ti-lock-open" aria-hidden="true"></i> Réouvrir
        </button>
      </div>

      <!-- Snapshot -->
      <SnapshotDisplay :snapshot="close.snapshot" />
    </template>

    <!-- Modal réouverture -->
    <ReopenModal
      :close="close"
      :is-open="showReopenModal"
      :submitting="submitting"
      @close="showReopenModal = false"
      @confirm="onConfirmReopen"
    />
  </div>
</template>

<style scoped>
.detail-view { padding: 24px; max-width: 1100px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }

.page-header { display: flex; flex-direction: column; gap: 10px; }
.back-link { background: transparent; border: none; color: var(--pms-ink-3); font-size: 12px; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 4px; align-self: flex-start; }
.back-link:hover { color: var(--pms-ink); }

.title-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
.title-row h1 { font-size: 22px; font-weight: 500; margin: 0; }
.t-muted { color: var(--pms-ink-3); font-size: 13px; margin: 4px 0 0; }

.badge { padding: 4px 14px; border-radius: 100px; font-size: 11px; font-weight: 500; flex-shrink: 0; }
.badge-effective { background: #D4EDE0; color: #2E7D4F; }
.badge-reopened  { background: #F5DADA; color: #B83232; }

.loading { padding: 40px 0; text-align: center; color: var(--pms-ink-3); }

.banner {
  display: flex; gap: 12px; padding: 12px 16px;
  border-radius: 10px; font-size: 13px;
}
.banner i { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
.banner em { font-style: italic; color: var(--pms-ink-3); }

.banner-reopened { background: #FBE5E5; border-left: 3px solid #B83232; color: #6B6459; }
.banner-reopened i { color: #B83232; }
.banner-reopened strong { color: #B83232; }

.banner-forced { background: #FBF6E8; border-left: 3px solid #C4922A; color: #6B6459; }
.banner-forced i { color: #8A6319; }
.banner-forced strong { color: #8A6319; }

.actions { display: flex; gap: 8px; justify-content: flex-end; }

.btn { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 16px; border-radius: 10px; border: none; font-family: var(--font); font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-secondary { background: #fff; color: var(--pms-ink); border: 0.5px solid var(--pms-border-2); }
.btn-secondary:hover:not(:disabled) { background: var(--pms-sand, #F5F0E8); }
.btn-warning { background: #C4922A; color: #fff; }
.btn-warning:hover:not(:disabled) { background: #8A6319; }
</style>
