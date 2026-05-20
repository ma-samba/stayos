<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useReservationsStore } from '@/stores/reservations.store'
import type { ReservationStatus, Reservation } from '@/types/entities'
import { formatCurrency } from '@/utils/currency'
import ReservationForm from '@/modules/reservations/components/ReservationForm.vue'
import CheckInModal from '@/modules/reservations/components/CheckInModal.vue'
import CheckOutModal from '@/modules/reservations/components/CheckOutModal.vue'

const store  = useReservationsStore()
const router = useRouter()

// ── Filtres ──

const activeFilter = ref<ReservationStatus | 'all' | 'arrivals_today' | 'departures_today'>('all')

const statusFilters: { value: typeof activeFilter.value; label: string }[] = [
  { value: 'all',              label: 'Toutes' },
  { value: 'arrivals_today',   label: 'Arrivées du jour' },
  { value: 'departures_today', label: 'Départs du jour' },
  { value: 'confirmed',        label: 'Confirmées' },
  { value: 'checked_in',       label: 'En séjour' },
  { value: 'checked_out',      label: 'Terminées' },
  { value: 'cancelled',        label: 'Annulées' },
]

function isSameDay(dateStr: string, ref: Date): boolean {
  const d = new Date(dateStr)
  return d.getFullYear() === ref.getFullYear()
      && d.getMonth() === ref.getMonth()
      && d.getDate() === ref.getDate()
}

const filteredReservations = computed(() => {
  const today = new Date()

  if (activeFilter.value === 'arrivals_today') {
    return store.reservations.filter(r =>
      isSameDay(r.checkIn, today) &&
      (r.status === 'confirmed' || r.status === 'pending')
    )
  }
  if (activeFilter.value === 'departures_today') {
    return store.reservations.filter(r =>
      isSameDay(r.checkOut, today) && r.status === 'checked_in'
    )
  }
  return store.filterByStatus(activeFilter.value as ReservationStatus | 'all')
})

function openDetail(id: string): void {
  router.push(`/reservations/${id}`)
}

// ── Modales ──

const showForm       = ref(false)
const editTarget     = ref<Reservation | null>(null)
const checkInTarget  = ref<string | null>(null)
const checkOutTarget = ref<string | null>(null)
const cancelTarget   = ref<string | null>(null)
const cancelReason   = ref('')

// ── Actions ──

async function handleCancel(): Promise<void> {
  if (!cancelTarget.value) return
  try {
    await store.cancel(cancelTarget.value, cancelReason.value || 'Annulation sans motif')
    cancelTarget.value = null
    cancelReason.value = ''
  } catch { /* toast handled by interceptor */ }
}

// ── Helpers ──

const statusBadgeClass: Record<ReservationStatus, string> = {
  confirmed:   'badge-confirmed',
  pending:     'badge-pending',
  checked_in:  'badge-checkin',
  checked_out: 'badge-checkout',
  cancelled:   'badge-cancelled',
  no_show:     'badge-cancelled',
}

const statusLabels: Record<ReservationStatus, string> = {
  confirmed:   'Confirmée',
  pending:     'En attente',
  checked_in:  'En séjour',
  checked_out: 'Terminée',
  cancelled:   'Annulée',
  no_show:     'Non présenté',
}

function formatDate(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleDateString('fr-SN', { day: '2-digit', month: 'short', year: 'numeric' })
}

function guestInitials(firstName: string, lastName: string): string {
  return (firstName[0] + lastName[0]).toUpperCase()
}

function nights(checkIn: string, checkOut: string): number {
  const ci = new Date(checkIn)
  const co = new Date(checkOut)
  return Math.round((co.getTime() - ci.getTime()) / (1000 * 60 * 60 * 24))
}

// ── Handlers modales ──

async function onCheckInDone(): Promise<void> {
  checkInTarget.value = null
  await store.fetchReservations()
}

async function onCheckOutDone(): Promise<void> {
  checkOutTarget.value = null
  await store.fetchReservations()
}

// ── Lifecycle ──

onMounted(() => {
  store.fetchReservations()
})
</script>

<template>
  <div style="padding:1.5rem; max-width:1400px; margin:0 auto;">

    <!-- ── En-tête ── -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
      <div>
        <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
          Réservations
        </h1>
        <p class="t-muted">Gestion des séjours et arrivées/départs</p>
      </div>
      <button class="btn btn-primary" @click="showForm = true">
        <i class="ti ti-plus" aria-hidden="true"></i> Nouvelle réservation
      </button>
    </div>

    <!-- ── Stat cards ── -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:1.5rem;">
      <div class="stat-card">
        <div class="stat-label">Confirmées</div>
        <div class="stat-value" style="color:var(--pms-blue);">{{ store.confirmedCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">En séjour</div>
        <div class="stat-value" style="color:var(--pms-teal);">{{ store.checkedInCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Terminées</div>
        <div class="stat-value" style="color:var(--pms-green);">{{ store.checkedOutCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Annulées</div>
        <div class="stat-value" style="color:var(--pms-red);">{{ store.cancelledCount }}</div>
      </div>
    </div>

    <!-- ── Filtres par statut ── -->
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.5rem;">
      <button
        v-for="f in statusFilters"
        :key="f.value"
        :class="['btn', 'btn-sm', activeFilter === f.value ? 'btn-primary' : 'btn-secondary']"
        @click="activeFilter = f.value"
      >
        {{ f.label }}
      </button>
    </div>

    <!-- ── Chargement ── -->
    <div v-if="store.loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>

    <!-- ── Erreur ── -->
    <div v-else-if="store.error" class="empty-state">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <div>{{ store.error }}</div>
      <button class="btn btn-secondary btn-sm" @click="store.fetchReservations()">Réessayer</button>
    </div>

    <!-- ── Aucun résultat ── -->
    <div v-else-if="filteredReservations.length === 0" class="empty-state">
      <i class="ti ti-calendar-off" aria-hidden="true"></i>
      <div>Aucune réservation</div>
      <button class="btn btn-primary btn-sm" @click="showForm = true">Créer une réservation</button>
    </div>

    <!-- ── Tableau ── -->
    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Référence</th>
            <th>Client</th>
            <th>Chambre</th>
            <th>Arrivée</th>
            <th>Départ</th>
            <th>Nuits</th>
            <th>Total</th>
            <th>Statut</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in filteredReservations" :key="r.id" style="cursor:pointer;" @click="openDetail(r.id)">
            <td><span class="t-mono">{{ r.confirmationNumber }}</span></td>
            <td>
              <div style="display:flex; align-items:center; gap:8px;">
                <div class="avatar avatar-sm avatar-teal">
                  {{ guestInitials(r.guest.firstName, r.guest.lastName) }}
                </div>
                {{ r.guest.firstName }} {{ r.guest.lastName }}
              </div>
            </td>
            <td>
              <span class="t-mono">{{ r.room.number }}</span>
              <span class="t-muted" style="margin-left:4px;">{{ r.room.type?.name }}</span>
            </td>
            <td>{{ formatDate(r.checkIn) }}</td>
            <td>{{ formatDate(r.checkOut) }}</td>
            <td>{{ nights(r.checkIn, r.checkOut) }}</td>
            <td style="font-weight:500;">{{ formatCurrency(r.totalXof) }}</td>
            <td>
              <span :class="['badge', statusBadgeClass[r.status]]">
                <span class="badge-dot"></span>{{ statusLabels[r.status] }}
              </span>
            </td>
            <td>
              <div style="display:flex; gap:4px;">
                <!-- Détail -->
                <button class="btn btn-ghost btn-icon-sm" title="Détail" @click.stop="openDetail(r.id)">
                  <i class="ti ti-eye" aria-hidden="true"></i>
                </button>
                <!-- Modifier (si confirmée ou en attente) -->
                <button
                  v-if="r.status === 'confirmed' || r.status === 'pending'"
                  class="btn btn-ghost btn-icon-sm"
                  title="Modifier"
                  @click.stop="editTarget = r"
                >
                  <i class="ti ti-edit" aria-hidden="true"></i>
                </button>
                <!-- Check-in (si confirmée) -->
                <button
                  v-if="r.status === 'confirmed'"
                  class="btn btn-ghost btn-icon-sm"
                  title="Check-in"
                  @click.stop="checkInTarget = r.id"
                >
                  <i class="ti ti-login" aria-hidden="true"></i>
                </button>
                <!-- Check-out (si en séjour) -->
                <button
                  v-if="r.status === 'checked_in'"
                  class="btn btn-ghost btn-icon-sm"
                  title="Check-out"
                  @click.stop="checkOutTarget = r.id"
                >
                  <i class="ti ti-logout" aria-hidden="true"></i>
                </button>
                <!-- Annuler (si confirmée ou en attente) -->
                <button
                  v-if="r.status === 'confirmed' || r.status === 'pending'"
                  class="btn btn-ghost btn-icon-sm"
                  title="Annuler"
                  @click.stop="cancelTarget = r.id; cancelReason = ''"
                >
                  <i class="ti ti-x" aria-hidden="true"></i>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Modal Création ── -->
    <ReservationForm
      v-if="showForm"
      @close="showForm = false"
      @created="showForm = false; store.fetchReservations()"
    />

    <!-- ── Modal Check-in ── -->
    <CheckInModal
      v-if="checkInTarget"
      :reservation-id="checkInTarget"
      @close="checkInTarget = null"
      @done="onCheckInDone()"
    />

    <!-- ── Modal Check-out ── -->
    <CheckOutModal
      v-if="checkOutTarget"
      :reservation-id="checkOutTarget"
      :total="store.reservations.find(r => r.id === checkOutTarget)?.totalXof"
      @close="checkOutTarget = null"
      @done="onCheckOutDone()"
    />

    <!-- ── Modal Annulation ── -->
    <div
      v-if="cancelTarget"
      style="position:fixed; inset:0; background:rgba(26,23,20,0.4); display:flex; align-items:center; justify-content:center; z-index:100;"
      @click.self="cancelTarget = null"
    >
      <div style="background:#fff; border-radius:var(--radius-xl); padding:1.5rem; width:400px; max-width:90vw;">
        <h3 style="font-size:16px; font-weight:500; color:var(--pms-ink); margin-bottom:12px;">
          Annuler la réservation
        </h3>
        <div class="input-wrap" style="margin-bottom:1rem;">
          <label class="input-label">Motif d'annulation</label>
          <input v-model="cancelReason" class="input" placeholder="Ex: Demande du client..." />
        </div>
        <div style="display:flex; gap:8px; justify-content:flex-end;">
          <button class="btn btn-ghost btn-sm" @click="cancelTarget = null">Fermer</button>
          <button class="btn btn-danger btn-sm" @click="handleCancel()">Annuler la réservation</button>
        </div>
      </div>
    </div>

    <!-- ── Modal Édition ── -->
    <ReservationForm
      v-if="editTarget"
      :reservation="editTarget"
      @close="editTarget = null"
      @created="editTarget = null; store.fetchReservations()"
    />

  </div>
</template>

<style scoped>
.badge-confirmed   { background: var(--pms-blue-light); color: var(--pms-blue); }
.badge-confirmed .badge-dot { background: var(--pms-blue); }
.badge-pending     { background: var(--pms-gold-light); color: var(--pms-gold-dark); }
.badge-pending .badge-dot { background: var(--pms-gold); }
.badge-checkin     { background: var(--pms-teal-light); color: var(--pms-teal-dark); }
.badge-checkin .badge-dot { background: var(--pms-teal); }
.badge-checkout    { background: var(--pms-green-light); color: var(--pms-green); }
.badge-checkout .badge-dot { background: var(--pms-green); }
.badge-cancelled   { background: var(--pms-red-light); color: var(--pms-red); }
.badge-cancelled .badge-dot { background: var(--pms-red); }
</style>
