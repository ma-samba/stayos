<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useReservationsStore } from '@/stores/reservations.store'
import type { Reservation, ReservationStatus, Invoice } from '@/types/entities'
import { formatCurrency } from '@/utils/currency'
import api from '@/services/api.service'
import type { ApiSuccess } from '@/types/entities'
import { invoiceService } from '@/services/invoice.service'
import ReservationForm from '@/modules/reservations/components/ReservationForm.vue'

const route  = useRoute()
const router = useRouter()
const store  = useReservationsStore()

const reservation    = ref<Reservation | null>(null)
const linkedInvoice  = ref<Invoice | null>(null)
const loading = ref(true)
const error   = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get<ApiSuccess<Reservation>>(`/reservations/${route.params.id}`)
    reservation.value = data.data

    // Chercher la facture associee (check-in ou check-out)
    if (reservation.value.status === 'checked_in' || reservation.value.status === 'checked_out') {
      linkedInvoice.value = await invoiceService.byReservation(reservation.value.id)
    }
  } catch {
    error.value = 'Réservation introuvable'
  } finally {
    loading.value = false
  }
}

function formatDate(s: string): string {
  return new Date(s).toLocaleDateString('fr-SN', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
}

function nights(checkIn: string, checkOut: string): number {
  const ci = new Date(checkIn)
  const co = new Date(checkOut)
  return Math.round((co.getTime() - ci.getTime()) / (1000 * 60 * 60 * 24))
}

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

const showEditForm = ref(false)

async function onEdited(): Promise<void> {
  showEditForm.value = false
  await load()
}

function goBack(): void {
  if (window.history.length > 1) {
    router.back()
  } else {
    router.push('/reservations')
  }
}

onMounted(load)
</script>

<template>
  <div style="padding:1.5rem; max-width:900px; margin:0 auto;">
    <button class="btn btn-ghost btn-sm" style="margin-bottom:1rem;" @click="goBack()">
      <i class="ti ti-arrow-left"></i> Retour
    </button>

    <!-- Chargement -->
    <div v-if="loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>

    <!-- Erreur -->
    <div v-else-if="error" class="empty-state">
      <i class="ti ti-alert-circle"></i>
      <div>{{ error }}</div>
      <button class="btn btn-secondary btn-sm" @click="goBack()">Retour</button>
    </div>

    <div v-else-if="reservation">
      <!-- En-tête -->
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem;">
        <div>
          <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
            <span class="t-mono">{{ reservation.confirmationNumber }}</span>
          </h1>
          <p class="t-muted">Détail de la réservation</p>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
          <button
            v-if="reservation.status === 'confirmed' || reservation.status === 'pending'"
            class="btn btn-secondary"
            @click="showEditForm = true"
          >
            <i class="ti ti-edit"></i> Modifier
          </button>
          <button
            v-if="linkedInvoice"
            class="btn btn-secondary"
            @click="router.push(`/invoices/${linkedInvoice.id}`)"
          >
            <i class="ti ti-file-invoice"></i> Voir la facture
          </button>
          <span :class="['badge', statusBadgeClass[reservation.status]]">
            <span class="badge-dot"></span>{{ statusLabels[reservation.status] }}
          </span>
        </div>
      </div>

      <!-- Bloc client -->
      <div class="card" style="padding:1.25rem; margin-bottom:1rem;">
        <div style="font-size:11px; font-weight:500; color:var(--pms-ink-3); letter-spacing:0.04em; text-transform:uppercase; margin-bottom:8px;">Client</div>
        <div style="display:flex; align-items:center; gap:12px;">
          <div class="avatar avatar-md avatar-teal">
            {{ (reservation.guest.firstName[0] + reservation.guest.lastName[0]).toUpperCase() }}
          </div>
          <div>
            <div style="font-weight:500; font-size:15px;">{{ reservation.guest.firstName }} {{ reservation.guest.lastName }}</div>
            <div class="t-muted" style="font-size:13px;">
              {{ reservation.guest.email || '—' }} · {{ reservation.guest.phone || '—' }}
            </div>
          </div>
        </div>
      </div>

      <!-- Bloc séjour -->
      <div class="card" style="padding:1.25rem; margin-bottom:1rem;">
        <div style="font-size:11px; font-weight:500; color:var(--pms-ink-3); letter-spacing:0.04em; text-transform:uppercase; margin-bottom:12px;">Séjour</div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
          <div>
            <div class="t-muted" style="font-size:12px;">Chambre</div>
            <div style="font-weight:500;">
              <span class="t-mono">{{ reservation.room.number }}</span>
              <span v-if="reservation.room.type" class="t-muted" style="margin-left:6px;">{{ reservation.room.type.name }}</span>
            </div>
          </div>
          <div>
            <div class="t-muted" style="font-size:12px;">Occupants</div>
            <div>{{ reservation.adults }} adulte{{ reservation.adults > 1 ? 's' : '' }}<template v-if="reservation.children">, {{ reservation.children }} enfant{{ reservation.children > 1 ? 's' : '' }}</template></div>
          </div>
          <div>
            <div class="t-muted" style="font-size:12px;">Arrivée</div>
            <div>{{ formatDate(reservation.checkIn) }}</div>
          </div>
          <div>
            <div class="t-muted" style="font-size:12px;">Départ</div>
            <div>{{ formatDate(reservation.checkOut) }}</div>
          </div>
          <div>
            <div class="t-muted" style="font-size:12px;">Nuitées</div>
            <div style="font-weight:500;">{{ nights(reservation.checkIn, reservation.checkOut) }}</div>
          </div>
          <div>
            <div class="t-muted" style="font-size:12px;">Source</div>
            <div>{{ reservation.source }}</div>
          </div>
        </div>
      </div>

      <!-- Bloc tarif -->
      <div class="card-sand" style="padding:1.25rem; margin-bottom:1rem;">
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
          <span class="t-muted">Tarif / nuit</span>
          <span>{{ formatCurrency(reservation.rateXof) }}</span>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:16px; font-weight:500; color:var(--pms-ink);">
          <span>Total séjour</span>
          <span>{{ formatCurrency(reservation.totalXof) }}</span>
        </div>
      </div>

      <!-- Notes -->
      <div v-if="reservation.notes || reservation.specialRequests" class="card" style="padding:1.25rem; margin-bottom:1rem;">
        <div v-if="reservation.notes" style="margin-bottom:12px;">
          <div class="t-muted" style="font-size:12px; margin-bottom:4px;">Notes</div>
          <div style="font-size:14px;">{{ reservation.notes }}</div>
        </div>
        <div v-if="reservation.specialRequests">
          <div class="t-muted" style="font-size:12px; margin-bottom:4px;">Demandes spéciales</div>
          <div style="font-size:14px;">{{ reservation.specialRequests }}</div>
        </div>
      </div>
    </div>

    <!-- Modal édition -->
    <ReservationForm
      v-if="showEditForm && reservation"
      :reservation="reservation"
      @close="showEditForm = false"
      @created="onEdited()"
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
