<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { staffService } from '@/services/staff.service'
import { useAuthStore } from '@/stores/auth.store'
import InviteStaffModal from '@/modules/staff/components/InviteStaffModal.vue'
import CreateStaffModal from '@/modules/staff/components/CreateStaffModal.vue'
import type { StaffInvitation, StaffMember, InvitationStatus } from '@/types/staff'
import { useNotificationsStore } from '@/stores/notifications.store'

const notif = useNotificationsStore()

const router = useRouter()
const auth   = useAuthStore()

function isSelf(member: StaffMember): boolean {
  return auth.userId !== null && member.id === auth.userId
}

const staff       = ref<StaffMember[]>([])
const invitations = ref<StaffInvitation[]>([])
const loading     = ref(true)
const error       = ref<string | null>(null)

const showInvite  = ref(false)
const showCreate  = ref(false)
const showInactive = ref(false)

const ROLE_LABEL: Record<string, string> = {
  MANAGER:       'Manager',
  RECEPTIONIST:  'Réceptionniste',
  ACCOUNTANT:    'Comptable',
  HOUSEKEEPER:   'Ménage',
}

const STATUS_LABEL: Record<InvitationStatus, string> = {
  pending:  'En attente',
  accepted: 'Acceptée',
  expired:  'Expirée',
  revoked:  'Révoquée',
}

const activeStaff   = computed(() => staff.value.filter((s) => s.active))
const inactiveStaff = computed(() => staff.value.filter((s) => !s.active))

const usage = computed(() => ({
  active:  activeStaff.value.length,
  pending: invitations.value.filter((i) => i.status === 'pending').length,
}))

async function load(): Promise<void> {
  loading.value = true
  error.value   = null
  try {
    const [s, inv] = await Promise.all([
      staffService.listStaff(),
      staffService.listInvitations(),
    ])
    staff.value       = s
    invitations.value = inv
  } catch (e) {
    error.value = "Impossible de charger l'équipe."
    console.error(e)
  } finally {
    loading.value = false
  }
}

async function deactivate(member: StaffMember): Promise<void> {
  if (!window.confirm(`Désactiver ${member.fullName} ?`)) return
  try {
    await staffService.deactivateStaff(member.id)
    await load()
    notif.pushUiToast('success', `${member.fullName} désactivé(e).`)
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { error?: string } } }).response?.data?.error
    notif.pushUiToast('alert', msg ?? 'Erreur lors de la désactivation.')
  }
}

async function reactivate(member: StaffMember): Promise<void> {
  try {
    await staffService.reactivateStaff(member.id)
    await load()
    notif.pushUiToast('success', `${member.fullName} réactivé(e).`)
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { error?: string } } }).response?.data?.error
    notif.pushUiToast('alert', msg ?? 'Erreur lors de la réactivation.')
  }
}

async function revoke(inv: StaffInvitation): Promise<void> {
  if (!window.confirm(`Révoquer l'invitation pour ${inv.email} ?`)) return
  try {
    await staffService.revokeInvitation(inv.id)
    await load()
    notif.pushUiToast('success', 'Invitation révoquée.')
  } catch {
    notif.pushUiToast('alert', 'Erreur lors de la révocation.')
  }
}

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('fr-SN', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('fr-SN', {
    day: '2-digit', month: 'short', year: 'numeric',
  })
}

function gotoDetail(id: string): void {
  router.push(`/staff/${id}`)
}

onMounted(load)
</script>

<template>
  <div class="staff-page">
    <header class="staff-head">
      <div>
        <h1>Personnel</h1>
        <p class="t-muted">Gestion de l'équipe de l'hôtel.</p>
      </div>
      <div class="staff-head-actions">
        <button class="btn btn-secondary btn-sm" @click="showCreate = true">
          <i class="ti ti-user-plus"></i> Créer un compte
        </button>
        <button class="btn btn-primary btn-sm" @click="showInvite = true">
          <i class="ti ti-mail"></i> Inviter un employé
        </button>
      </div>
    </header>

    <!-- Stat card usage -->
    <div v-if="!loading" class="staff-stats">
      <div class="stat-card">
        <div class="stat-label">Membres actifs</div>
        <div class="stat-value">{{ usage.active }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Invitations en attente</div>
        <div class="stat-value">{{ usage.pending }}</div>
      </div>
    </div>

    <!-- Loading / error -->
    <div v-if="loading" class="staff-loading">Chargement…</div>
    <div v-else-if="error" class="staff-error">
      <i class="ti ti-alert-circle"></i> {{ error }}
    </div>

    <template v-else>
      <!-- Membres actifs -->
      <section class="staff-section">
        <h2 class="staff-section-title">Membres actifs ({{ activeStaff.length }})</h2>
        <div v-if="activeStaff.length === 0" class="staff-empty">
          Aucun membre actif. Invitez votre premier employé.
        </div>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Email</th>
                <th>Nom</th>
                <th>Rôle</th>
                <th>Téléphone</th>
                <th>Dernier login</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in activeStaff" :key="m.id" class="staff-row" @click="gotoDetail(m.id)">
                <td>{{ m.email }}</td>
                <td>{{ m.fullName }}</td>
                <td>{{ ROLE_LABEL[m.role] ?? m.role }}</td>
                <td>{{ m.phone ?? '—' }}</td>
                <td>{{ formatDateTime(m.lastLoginAt) }}</td>
                <td class="staff-actions-cell">
                  <button class="btn btn-ghost btn-sm" @click.stop="gotoDetail(m.id)">Voir</button>
                  <button
                    v-if="!isSelf(m)"
                    class="btn btn-ghost btn-sm staff-danger"
                    @click.stop="deactivate(m)"
                  >
                    Désactiver
                  </button>
                  <span v-else class="staff-self-marker">Vous</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Invitations -->
      <section class="staff-section">
        <h2 class="staff-section-title">Invitations ({{ invitations.length }})</h2>
        <div v-if="invitations.length === 0" class="staff-empty">
          Aucune invitation pour le moment.
        </div>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Email</th>
                <th>Nom</th>
                <th>Rôle</th>
                <th>Statut</th>
                <th>Expire</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in invitations" :key="inv.id">
                <td>{{ inv.email }}</td>
                <td>{{ inv.firstName }} {{ inv.lastName }}</td>
                <td>{{ ROLE_LABEL[inv.role] ?? inv.role }}</td>
                <td>
                  <span :class="['staff-badge', `staff-badge--${inv.status}`]">
                    {{ STATUS_LABEL[inv.status] }}
                  </span>
                </td>
                <td>{{ formatDate(inv.expiresAt) }}</td>
                <td class="staff-actions-cell">
                  <button
                    v-if="inv.status === 'pending'"
                    class="btn btn-ghost btn-sm staff-danger"
                    @click="revoke(inv)"
                  >
                    Révoquer
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Membres désactivés (repliable) -->
      <section v-if="inactiveStaff.length > 0" class="staff-section">
        <button class="staff-toggle" @click="showInactive = !showInactive">
          <i :class="showInactive ? 'ti ti-chevron-down' : 'ti ti-chevron-right'"></i>
          Membres désactivés ({{ inactiveStaff.length }})
        </button>
        <div v-if="showInactive" class="table-wrap" style="margin-top:0.5rem;">
          <table>
            <thead>
              <tr>
                <th>Email</th>
                <th>Nom</th>
                <th>Rôle</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in inactiveStaff" :key="m.id">
                <td>{{ m.email }}</td>
                <td>{{ m.fullName }}</td>
                <td>{{ ROLE_LABEL[m.role] ?? m.role }}</td>
                <td class="staff-actions-cell">
                  <button class="btn btn-secondary btn-sm" @click="reactivate(m)">
                    Réactiver
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <InviteStaffModal
      v-if="showInvite"
      @close="showInvite = false"
      @created="load(); notif.pushUiToast('success', 'Invitation envoyée.')"
    />
    <CreateStaffModal
      v-if="showCreate"
      @close="showCreate = false"
      @created="load(); notif.pushUiToast('success', 'Compte créé.')"
    />
  </div>
</template>

<style scoped>
.staff-page {
  padding: 1.5rem;
  max-width: 1400px;
  margin: 0 auto;
}

.staff-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.5rem;
  gap: 1rem;
  flex-wrap: wrap;
}
.staff-head h1 {
  font-size: 22px;
  font-weight: 500;
  color: var(--pms-ink);
  margin: 0 0 4px;
}
.t-muted { color: var(--pms-ink-3); font-size: 13px; }

.staff-head-actions {
  display: flex; gap: 8px;
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

.staff-stats {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
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

.staff-section { margin-bottom: 1.5rem; }
.staff-section-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--pms-ink);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin: 0 0 0.75rem;
}

.staff-empty {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-md);
  padding: 1.5rem;
  text-align: center;
  color: var(--pms-ink-3);
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

.staff-row { cursor: pointer; }
.staff-row:hover td { background: #faf9f7; }

.staff-self-marker {
  display: inline-flex;
  align-items: center;
  padding: 6px 10px;
  font-size: 12px;
  color: var(--pms-ink-3);
  font-style: italic;
}

.staff-actions-cell {
  text-align: right;
  white-space: nowrap;
}
.staff-danger { color: var(--pms-red); }
.staff-danger:hover { background: var(--pms-red-light); }

.staff-badge {
  display: inline-flex;
  padding: 3px 10px;
  border-radius: 100px;
  font-size: 11px;
  font-weight: 500;
}
.staff-badge--pending  { background: var(--pms-gold-light); color: var(--pms-gold-dark); }
.staff-badge--accepted { background: var(--pms-green-light); color: var(--pms-green); }
.staff-badge--expired  { background: rgba(26,23,20,0.08); color: var(--pms-ink-3); }
.staff-badge--revoked  { background: var(--pms-red-light); color: var(--pms-red); }

.staff-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: transparent;
  border: none;
  font-family: var(--font);
  font-size: 13px;
  color: var(--pms-ink-3);
  cursor: pointer;
  padding: 6px 0;
}
.staff-toggle:hover { color: var(--pms-ink); }
.staff-toggle i { font-size: 16px; }
</style>
