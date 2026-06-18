<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { staffService } from '@/services/staff.service'
import { useAuthStore } from '@/stores/auth.store'
import type { StaffActivityEntry, StaffAuditEntry, StaffMember, StaffRole } from '@/types/staff'

const route   = useRoute()
const router  = useRouter()
const auth    = useAuthStore()

const id      = route.params.id as string
const member  = ref<StaffMember | null>(null)
const loading = ref(true)
const error   = ref<string | null>(null)
const flash   = ref<string | null>(null)

const firstName = ref('')
const lastName  = ref('')
const role      = ref<StaffRole>('RECEPTIONIST')
const phone     = ref('')

const showResetConfirm = ref(false)
const showDeactivateConfirm = ref(false)
const showResetResult = ref<string | null>(null)
const actionLoading = ref(false)
const actionError   = ref<string | null>(null)

const auditEntries    = ref<StaffAuditEntry[]>([])
const auditLoading    = ref(true)
const activityEntries = ref<StaffActivityEntry[]>([])
const activityLoading = ref(true)
const activeTab       = ref<'activity' | 'history'>('activity')

const ROLE_LABEL: Record<string, string> = {
  MANAGER:       'Manager',
  RECEPTIONIST:  'Réceptionniste',
  ACCOUNTANT:    'Comptable',
  HOUSEKEEPER:   'Ménage',
}

const ACTION_LABEL: Record<string, string> = {
  // ── Staff (historique compte) ──
  'staff_user.created':                'Compte créé',
  'staff_user.created_via_invitation': 'Compte créé via invitation',
  'staff_user.updated':                'Profil modifié',
  'staff_user.password_reset':         'Mot de passe réinitialisé',
  'staff_user.deactivated':            'Compte désactivé',
  'staff_user.reactivated':            'Compte réactivé',
  'staff_invitation.created':          'Invitation envoyée',
  'staff_invitation.revoked':          'Invitation révoquée',

  // ── Réservations ──
  'reservation.created':    'Réservation créée',
  'reservation.updated':    'Réservation modifiée',
  'reservation.cancelled':  'Réservation annulée',
  'reservation.checkin':    'Check-in',
  'reservation.checkout':   'Check-out',

  // ── Clients ──
  'guest.created': 'Client créé',
  'guest.updated': 'Client modifié',

  // ── Chambres ──
  'room.status_changed': 'Statut chambre modifié',
  'room.updated':        'Chambre modifiée',
  'room_type.updated':   'Type de chambre modifié',

  // ── Housekeeping ──
  'cleaning_task.assigned':       'Tâche ménage assignée',
  'cleaning_task.status_changed': 'Statut tâche ménage modifié',

  // ── Facturation / paiements ──
  'invoice.issued':    'Facture émise',
  'payment.recorded':  'Paiement enregistré',
}

const FIELD_LABEL: Record<string, string> = {
  firstName: 'prénom',
  lastName:  'nom',
  role:      'rôle',
  phone:     'téléphone',
}

function entryLabel(entry: StaffAuditEntry | StaffActivityEntry): string {
  const base = ACTION_LABEL[entry.action] ?? entry.action

  // ── Enrichissement contextuel ──
  if (entry.action.startsWith('reservation.') && (entry.before || entry.after)) {
    const room = (entry.after?.room as string | undefined)
      ?? (entry.before?.room as string | undefined)
    if (room) {
      return `${base} — chambre ${room}`
    }
  }

  if (entry.action === 'staff_user.updated' && entry.before && entry.after) {
    const changes: string[] = []
    for (const k of Object.keys(entry.after)) {
      if (entry.before[k] !== entry.after[k]) {
        changes.push(FIELD_LABEL[k] ?? k)
      }
    }
    if (changes.length > 0) {
      return `${base} (${changes.join(', ')})`
    }
  }

  return base
}

function entryColor(action: string): 'green' | 'gold' | 'red' | 'blue' | 'ink' {
  // Création / activation / encaissement → vert
  if (
    action === 'staff_user.created' ||
    action === 'staff_user.created_via_invitation' ||
    action === 'staff_user.reactivated' ||
    action === 'staff_invitation.created' ||
    action === 'reservation.created' ||
    action === 'reservation.checkin' ||
    action === 'guest.created' ||
    action === 'payment.recorded' ||
    action === 'invoice.issued'
  ) {
    return 'green'
  }

  // Sortie / clôture → bleu
  if (action === 'reservation.checkout') {
    return 'blue'
  }

  // Annulation / désactivation → rouge
  if (
    action === 'staff_user.deactivated' ||
    action === 'staff_invitation.revoked' ||
    action === 'reservation.cancelled' ||
    action.endsWith('.deleted')
  ) {
    return 'red'
  }

  // Modification → jaune
  if (
    action === 'staff_user.updated' ||
    action === 'staff_user.password_reset' ||
    action === 'reservation.updated' ||
    action === 'guest.updated' ||
    action === 'room.updated' ||
    action === 'room.status_changed' ||
    action === 'room_type.updated' ||
    action === 'cleaning_task.assigned' ||
    action === 'cleaning_task.status_changed'
  ) {
    return 'gold'
  }

  return 'ink'
}

async function load(): Promise<void> {
  loading.value = true
  error.value   = null
  try {
    // Pas d'endpoint getById direct ; on récupère via la liste
    const list = await staffService.listStaff()
    const found = list.find((m) => m.id === id)
    if (!found) {
      error.value = 'Employé introuvable.'
      return
    }
    member.value   = found
    firstName.value = found.firstName
    lastName.value  = found.lastName
    role.value      = (found.role as StaffRole)
    phone.value     = found.phone ?? ''
  } catch (e) {
    error.value = "Impossible de charger l'employé."
    console.error(e)
  } finally {
    loading.value = false
  }
}

async function loadAudit(): Promise<void> {
  auditLoading.value = true
  try {
    auditEntries.value = await staffService.getStaffAudit(id)
  } catch (e) {
    console.error(e)
  } finally {
    auditLoading.value = false
  }
}

async function loadActivity(): Promise<void> {
  activityLoading.value = true
  try {
    activityEntries.value = await staffService.getStaffActivity(id)
  } catch (e) {
    console.error(e)
  } finally {
    activityLoading.value = false
  }
}

function showFlash(message: string): void {
  flash.value = message
  window.setTimeout(() => { flash.value = null }, 4000)
}

async function save(): Promise<void> {
  if (!member.value) return
  actionLoading.value = true
  actionError.value   = null
  try {
    const updated = await staffService.updateStaff(member.value.id, {
      firstName: firstName.value.trim(),
      lastName:  lastName.value.trim(),
      role:      role.value,
      phone:     phone.value.trim() || null,
    })
    member.value = updated
    showFlash('Modifications enregistrées.')
    loadAudit()
  } catch (e: unknown) {
    actionError.value = (e as { response?: { data?: { error?: string } } }).response?.data?.error
      ?? "Erreur lors de l'enregistrement."
  } finally {
    actionLoading.value = false
  }
}

async function resetPassword(): Promise<void> {
  if (!member.value) return
  actionLoading.value = true
  actionError.value   = null
  try {
    const { tempPassword } = await staffService.resetPassword(member.value.id)
    showResetResult.value  = tempPassword
    showResetConfirm.value = false
    loadAudit()
  } catch (e: unknown) {
    actionError.value = (e as { response?: { data?: { error?: string } } }).response?.data?.error
      ?? 'Erreur lors du reset.'
  } finally {
    actionLoading.value = false
  }
}

async function deactivate(): Promise<void> {
  if (!member.value) return
  actionLoading.value = true
  actionError.value   = null
  try {
    await staffService.deactivateStaff(member.value.id)
    showDeactivateConfirm.value = false
    showFlash('Employé désactivé.')
    await refresh()
  } catch (e: unknown) {
    actionError.value = (e as { response?: { data?: { error?: string } } }).response?.data?.error
      ?? 'Erreur lors de la désactivation.'
  } finally {
    actionLoading.value = false
  }
}

async function reactivate(): Promise<void> {
  if (!member.value) return
  actionLoading.value = true
  actionError.value   = null
  try {
    await staffService.reactivateStaff(member.value.id)
    showFlash('Employé réactivé.')
    await refresh()
  } catch (e: unknown) {
    actionError.value = (e as { response?: { data?: { error?: string } } }).response?.data?.error
      ?? 'Erreur lors de la réactivation.'
  } finally {
    actionLoading.value = false
  }
}

function copyPassword(): void {
  if (showResetResult.value) {
    navigator.clipboard.writeText(showResetResult.value)
  }
}

const isSelf = () => member.value?.email === auth.claims?.sub
  || (member.value && auth.userId && member.value.id === auth.userId)

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('fr-SN', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

onMounted(() => {
  // Chargement parallèle membre + historique + activité
  load()
  loadAudit()
  loadActivity()
})

async function refresh(): Promise<void> {
  await Promise.all([load(), loadAudit(), loadActivity()])
}
</script>

<template>
  <div class="staff-detail">
    <button class="staff-back" @click="router.push('/staff')">
      <i class="ti ti-arrow-left"></i> Liste du personnel
    </button>

    <div v-if="loading" class="staff-loading">Chargement…</div>

    <div v-else-if="error" class="staff-error">
      <i class="ti ti-alert-circle"></i> {{ error }}
    </div>

    <template v-else-if="member">
      <header class="staff-head">
        <div>
          <h1>{{ member.fullName }}</h1>
          <p class="t-muted">{{ member.email }}</p>
        </div>
        <span :class="['staff-badge', member.active ? 'staff-badge--active' : 'staff-badge--inactive']">
          {{ member.active ? 'Actif' : 'Désactivé' }}
        </span>
      </header>

      <div v-if="flash" class="staff-flash">
        <i class="ti ti-circle-check"></i> {{ flash }}
      </div>

      <div class="staff-grid">
        <!-- Édition -->
        <section class="card">
          <h2 class="section-title">Informations</h2>
          <div class="input-wrap">
            <label class="input-label">Email (non modifiable)</label>
            <input class="input" :value="member.email" disabled />
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
            <div class="input-wrap">
              <label class="input-label">Prénom</label>
              <input v-model="firstName" class="input" type="text" :disabled="!member.active" />
            </div>
            <div class="input-wrap">
              <label class="input-label">Nom</label>
              <input v-model="lastName" class="input" type="text" :disabled="!member.active" />
            </div>
          </div>
          <div class="input-wrap">
            <label class="input-label">Rôle</label>
            <select v-model="role" class="input" :disabled="!member.active">
              <option value="MANAGER">Manager</option>
              <option value="RECEPTIONIST">Réceptionniste</option>
              <option value="ACCOUNTANT">Comptable</option>
              <option value="HOUSEKEEPER">Femme/Valet de chambre</option>
            </select>
          </div>
          <div class="input-wrap">
            <label class="input-label">Téléphone</label>
            <input v-model="phone" class="input" type="tel" :disabled="!member.active" />
          </div>
          <button
            v-if="member.active"
            class="btn btn-primary btn-sm"
            :disabled="actionLoading"
            @click="save"
          >
            Enregistrer
          </button>
        </section>

        <!-- Actions -->
        <section class="card">
          <h2 class="section-title">Actions</h2>

          <p class="t-muted" style="margin-bottom:1rem;">
            Dernier login : {{ formatDate(member.lastLoginAt) }}
          </p>

          <!-- Reset password -->
          <template v-if="member.active">
            <template v-if="!showResetConfirm && !showResetResult">
              <button class="btn btn-secondary" @click="showResetConfirm = true">
                <i class="ti ti-key"></i> Réinitialiser le mot de passe
              </button>
            </template>
            <template v-else-if="showResetConfirm">
              <p style="font-size:13px; margin-bottom:10px;">
                Confirmer la réinitialisation ? Un nouveau mot de passe sera
                affiché une seule fois.
              </p>
              <div style="display:flex; gap:8px;">
                <button class="btn btn-primary btn-sm" :disabled="actionLoading" @click="resetPassword">
                  Confirmer
                </button>
                <button class="btn btn-ghost btn-sm" @click="showResetConfirm = false">Annuler</button>
              </div>
            </template>
            <template v-else-if="showResetResult">
              <div class="warning-box">
                <i class="ti ti-alert-triangle"></i>
                <div>
                  <strong>Nouveau mot de passe — affiché une seule fois.</strong>
                </div>
              </div>
              <div class="password-display">
                <code>{{ showResetResult }}</code>
                <button class="btn btn-secondary btn-sm" @click="copyPassword">
                  <i class="ti ti-copy"></i> Copier
                </button>
              </div>
              <button class="btn btn-ghost btn-sm" @click="showResetResult = null">
                Fermer
              </button>
            </template>
          </template>

          <hr v-if="!isSelf()" class="staff-sep" />

          <!-- Désactiver / Réactiver -->
          <template v-if="member.active && !isSelf()">
            <template v-if="!showDeactivateConfirm">
              <button class="btn btn-danger" @click="showDeactivateConfirm = true">
                <i class="ti ti-user-off"></i> Désactiver cet employé
              </button>
            </template>
            <template v-else>
              <p style="font-size:13px; margin-bottom:10px;">
                Confirmer la désactivation de <strong>{{ member.fullName }}</strong> ?
                Le compte ne pourra plus se connecter.
              </p>
              <div style="display:flex; gap:8px;">
                <button class="btn btn-danger btn-sm" :disabled="actionLoading" @click="deactivate">
                  Désactiver
                </button>
                <button class="btn btn-ghost btn-sm" @click="showDeactivateConfirm = false">
                  Annuler
                </button>
              </div>
            </template>
          </template>

          <template v-else-if="!member.active">
            <button class="btn btn-primary" :disabled="actionLoading" @click="reactivate">
              <i class="ti ti-user-check"></i> Réactiver cet employé
            </button>
          </template>

          <template v-else-if="isSelf()">
            <p class="t-muted" style="font-size:12px; margin-top:1rem;">
              Vous ne pouvez pas vous désactiver vous-même.
            </p>
          </template>

          <div v-if="actionError" class="staff-error" style="margin-top:10px;">
            <i class="ti ti-alert-circle"></i> {{ actionError }}
          </div>
        </section>
      </div>

      <!-- ── Activité / Historique (onglets) ── -->
      <section class="card audit-card">
        <div class="staff-tabs">
          <button
            type="button"
            :class="['staff-tab', { 'staff-tab--active': activeTab === 'activity' }]"
            @click="activeTab = 'activity'"
          >
            Activité ({{ activityEntries.length }})
          </button>
          <button
            type="button"
            :class="['staff-tab', { 'staff-tab--active': activeTab === 'history' }]"
            @click="activeTab = 'history'"
          >
            Historique du compte ({{ auditEntries.length }})
          </button>
        </div>

        <!-- ── Onglet Activité ── -->
        <div v-if="activeTab === 'activity'">
          <div v-if="activityLoading" class="audit-empty">Chargement…</div>
          <div v-else-if="activityEntries.length === 0" class="audit-empty">
            Aucune activité enregistrée pour cet employé.
          </div>
          <ul v-else class="audit-list">
            <li v-for="entry in activityEntries" :key="entry.id" class="audit-item">
              <div :class="['audit-dot', `audit-dot--${entryColor(entry.action)}`]"></div>
              <div class="audit-body">
                <div class="audit-label">{{ entryLabel(entry) }}</div>
                <div class="audit-meta">
                  <span>{{ formatDate(entry.createdAt) }}</span>
                </div>
              </div>
            </li>
          </ul>
        </div>

        <!-- ── Onglet Historique du compte ── -->
        <div v-else>
          <div v-if="auditLoading" class="audit-empty">Chargement…</div>
          <div v-else-if="auditEntries.length === 0" class="audit-empty">
            Aucune action enregistrée sur ce compte.
          </div>
          <ul v-else class="audit-list">
            <li v-for="entry in auditEntries" :key="entry.id" class="audit-item">
              <div :class="['audit-dot', `audit-dot--${entryColor(entry.action)}`]"></div>
              <div class="audit-body">
                <div class="audit-label">{{ entryLabel(entry) }}</div>
                <div class="audit-meta">
                  <span>{{ formatDate(entry.createdAt) }}</span>
                  <span v-if="entry.staffUserEmail">
                    · par {{ entry.staffUserEmail }}
                  </span>
                  <span v-else>
                    · acteur externe
                  </span>
                </div>
              </div>
            </li>
          </ul>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.staff-detail {
  padding: 1.5rem;
  max-width: 1100px;
  margin: 0 auto;
}

.staff-back {
  display: inline-flex; align-items: center; gap: 4px;
  background: transparent;
  border: none;
  color: var(--pms-ink-3);
  font-family: var(--font);
  font-size: 13px;
  margin-bottom: 1rem;
  cursor: pointer;
  padding: 0;
}
.staff-back:hover { color: var(--pms-ink); }

.staff-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}
.staff-head h1 {
  font-size: 22px;
  font-weight: 500;
  color: var(--pms-ink);
  margin: 0 0 4px;
}
.t-muted { color: var(--pms-ink-3); font-size: 13px; }

.staff-flash {
  display: flex; align-items: center; gap: 8px;
  background: var(--pms-green-light);
  color: var(--pms-green);
  padding: 10px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
  margin-bottom: 1rem;
}

.staff-error {
  display: flex; align-items: center; gap: 8px;
  background: var(--pms-red-light);
  color: var(--pms-red);
  padding: 10px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
}

.staff-loading {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  padding: 3rem;
  text-align: center;
  color: var(--pms-ink-3);
}

.staff-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 1rem;
}

.card {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  padding: 1.25rem 1.5rem;
}
.section-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--pms-ink);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin: 0 0 1rem;
}

.input-wrap { margin-bottom: 0.85rem; }
.input-label {
  display: block;
  font-size: 11px;
  font-weight: 500;
  color: var(--pms-ink-3);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin-bottom: 5px;
}
.input {
  width: 100%;
  height: 38px;
  padding: 0 12px;
  border: 0.5px solid var(--pms-border-2);
  border-radius: var(--radius-md);
  font-family: var(--font);
  font-size: 13px;
  background: #fff;
}
.input:disabled {
  background: var(--pms-sand-2);
  color: var(--pms-ink-3);
}

.staff-badge {
  display: inline-flex;
  padding: 4px 12px;
  border-radius: 100px;
  font-size: 12px;
  font-weight: 500;
}
.staff-badge--active   { background: var(--pms-green-light); color: var(--pms-green); }
.staff-badge--inactive { background: rgba(26,23,20,0.08); color: var(--pms-ink-3); }

.warning-box {
  display: flex; gap: 10px;
  background: var(--pms-gold-light);
  color: var(--pms-gold-dark);
  padding: 12px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
  margin-bottom: 0.75rem;
}
.warning-box i { font-size: 18px; flex-shrink: 0; }

.password-display {
  display: flex; align-items: center; gap: 10px;
  background: var(--pms-sand-2);
  padding: 12px;
  border-radius: var(--radius-md);
  margin-bottom: 0.75rem;
}
.password-display code {
  flex: 1;
  font-family: var(--mono);
  font-size: 14px;
  font-weight: 500;
  word-break: break-all;
}

.staff-sep {
  border: none;
  border-top: 0.5px solid var(--pms-border);
  margin: 1.25rem 0;
}

/* ── Historique ── */
.audit-card {
  margin-top: 1rem;
}

.audit-empty {
  color: var(--pms-ink-3);
  font-size: 13px;
  padding: 0.5rem 0;
}

.audit-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.audit-item {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}

.audit-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-top: 6px;
  flex-shrink: 0;
}
.audit-dot--green { background: var(--pms-green); }
.audit-dot--gold  { background: var(--pms-gold); }
.audit-dot--red   { background: var(--pms-red); }
.audit-dot--blue  { background: var(--pms-blue); }
.audit-dot--ink   { background: var(--pms-ink-3); }

/* ── Onglets Activité / Historique ── */
.staff-tabs {
  display: flex;
  gap: 4px;
  border-bottom: 0.5px solid var(--pms-border);
  margin: -1.25rem -1.5rem 1.25rem;
  padding: 0 1.5rem;
}
.staff-tab {
  position: relative;
  background: transparent;
  border: none;
  padding: 12px 4px;
  margin-right: 1.5rem;
  font-family: var(--font);
  font-size: 13px;
  color: var(--pms-ink-3);
  cursor: pointer;
  transition: color 0.15s;
}
.staff-tab:hover { color: var(--pms-ink-2); }
.staff-tab--active {
  color: var(--pms-ink);
  font-weight: 500;
}
.staff-tab--active::after {
  content: '';
  position: absolute;
  left: 0; right: 0; bottom: -1px;
  height: 2px;
  background: var(--pms-ink);
}

.audit-body {
  flex: 1;
  min-width: 0;
}

.audit-label {
  font-size: 13px;
  color: var(--pms-ink);
  font-weight: 500;
}

.audit-meta {
  font-size: 12px;
  color: var(--pms-ink-3);
  margin-top: 2px;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
</style>
