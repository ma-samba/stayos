<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useReservationsStore } from '@/stores/reservations.store'
import { roomService } from '@/services/room.service'
import type { Room, Guest, Reservation } from '@/types/entities'
import { formatCurrency } from '@/utils/currency'
import api from '@/services/api.service'
import type { ApiSuccess } from '@/types/entities'

const emit = defineEmits<{
  close: []
  created: []
}>()

const props = defineProps<{
  reservation?: Reservation | null
}>()

const store = useReservationsStore()
const isEditMode = computed(() => !!props.reservation)

// ── Form data ──

const roomId          = ref('')
const guestId         = ref('')
const checkIn         = ref('')
const checkOut        = ref('')
const adults          = ref(1)
const children        = ref(0)
const notes           = ref('')
const specialRequests = ref('')
const source          = ref('direct')
const depositXof      = ref('')

const submitting = ref(false)
const formError  = ref<string | null>(null)

// ── Chambres disponibles ──

const availableRooms = ref<Room[]>([])
const loadingRooms   = ref(false)

async function searchRooms(): Promise<void> {
  if (!checkIn.value || !checkOut.value) return

  loadingRooms.value = true
  try {
    const rooms = await roomService.getAvailable(checkIn.value, checkOut.value, adults.value)
    // En édition : garantir que la chambre actuelle est dans la liste
    if (props.reservation && !rooms.some(r => r.id === props.reservation!.room.id)) {
      rooms.unshift(props.reservation.room as Room)
    }
    availableRooms.value = rooms
  } catch {
    availableRooms.value = []
  } finally {
    loadingRooms.value = false
  }
}

// ── Recherche clients ──

const guestQuery = ref('')
const guests     = ref<Guest[]>([])

async function searchGuests(): Promise<void> {
  if (guestQuery.value.length < 2) {
    guests.value = []
    return
  }
  try {
    const { data } = await api.get<ApiSuccess<Guest[]>>('/guests', {
      params: { q: guestQuery.value },
    })
    guests.value = data.data
  } catch {
    guests.value = []
  }
}

function selectGuest(guest: Guest): void {
  guestId.value    = guest.id
  guestQuery.value = `${guest.firstName} ${guest.lastName}`
  guests.value     = []
}

function onGuestQueryInput(): void {
  guestId.value = ''
  searchGuests()
}

// ── Création client inline ──

const showNewGuestForm = ref(false)
const creatingGuest    = ref(false)
const newGuestError    = ref<string | null>(null)
const newGuest = ref({
  firstName: '',
  lastName: '',
  email: '',
  phone: '',
})

function openNewGuestForm(): void {
  const parts = guestQuery.value.trim().split(/\s+/)
  newGuest.value.firstName = parts[0] ?? ''
  newGuest.value.lastName  = parts.slice(1).join(' ')
  newGuest.value.email     = ''
  newGuest.value.phone     = ''
  newGuestError.value      = null
  showNewGuestForm.value   = true
  guests.value             = []
}

async function createGuest(): Promise<void> {
  if (!newGuest.value.firstName || !newGuest.value.lastName) {
    newGuestError.value = 'Le prénom et le nom sont obligatoires.'
    return
  }

  creatingGuest.value = true
  newGuestError.value = null

  try {
    const { data } = await api.post<ApiSuccess<Guest>>('/guests', {
      firstName: newGuest.value.firstName,
      lastName:  newGuest.value.lastName,
      email:     newGuest.value.email || null,
      phone:     newGuest.value.phone || null,
    })
    selectGuest(data.data)
    showNewGuestForm.value = false
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'response' in e) {
      const err = e as { response: { data: { error?: string } } }
      newGuestError.value = err.response?.data?.error ?? 'Erreur lors de la création du client'
    } else {
      newGuestError.value = 'Erreur lors de la création du client'
    }
  } finally {
    creatingGuest.value = false
  }
}

// ── Calcul du total en temps réel ──

const selectedRoom = computed(() => availableRooms.value.find(r => r.id === roomId.value))

const nightsCount = computed(() => {
  if (!checkIn.value || !checkOut.value) return 0
  const ci = new Date(checkIn.value)
  const co = new Date(checkOut.value)
  const diff = Math.round((co.getTime() - ci.getTime()) / (1000 * 60 * 60 * 24))
  return diff > 0 ? diff : 0
})

const liveTotalRaw = computed(() => {
  if (!selectedRoom.value || nightsCount.value === 0) return 0
  return Number(selectedRoom.value.type.baseRateXof) * nightsCount.value
})

// ── Submit ──

async function submit(): Promise<void> {
  if (!checkIn.value || !checkOut.value) {
    formError.value = 'Veuillez sélectionner les dates d\'arrivée et de départ.'
    return
  }
  if (!roomId.value) {
    formError.value = 'Veuillez sélectionner une chambre disponible.'
    return
  }
  if (!guestId.value) {
    formError.value = 'Veuillez sélectionner un client dans la liste de suggestions.'
    return
  }

  submitting.value = true
  formError.value  = null

  try {
    const payload = {
      roomId:          roomId.value,
      guestId:         guestId.value,
      checkIn:         checkIn.value,
      checkOut:        checkOut.value,
      adults:          adults.value,
      children:        children.value,
      notes:           notes.value || undefined,
      specialRequests: specialRequests.value || undefined,
      source:          source.value,
      depositXof:      depositXof.value || undefined,
    }

    if (props.reservation) {
      await store.updateReservation(props.reservation.id, payload)
    } else {
      await store.createReservation(payload)
    }
    emit('created')
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'response' in e) {
      const err = e as { response: { data: { error: string } } }
      formError.value = err.response?.data?.error ?? 'Erreur lors de l\'enregistrement'
    } else {
      formError.value = 'Erreur lors de l\'enregistrement'
    }
  } finally {
    submitting.value = false
  }
}

// ── Pré-remplissage en mode édition ──

onMounted(() => {
  if (props.reservation) {
    const r = props.reservation
    checkIn.value         = r.checkIn.slice(0, 10)
    checkOut.value        = r.checkOut.slice(0, 10)
    adults.value          = r.adults
    children.value        = r.children
    roomId.value          = r.room.id
    guestId.value         = r.guest.id
    guestQuery.value      = `${r.guest.firstName} ${r.guest.lastName}`
    source.value          = r.source ?? 'direct'
    notes.value           = r.notes ?? ''
    specialRequests.value = r.specialRequests ?? ''
    searchRooms()
  }
})
</script>

<template>
  <div
    style="position:fixed; inset:0; background:rgba(26,23,20,0.4); display:flex; align-items:center; justify-content:center; z-index:100;"
    @click.self="emit('close')"
  >
    <div style="background:#fff; border-radius:var(--radius-xl); padding:1.5rem; width:560px; max-width:90vw; max-height:90vh; overflow-y:auto;">

      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
        <h3 style="font-size:18px; font-weight:500; color:var(--pms-ink);">
          {{ isEditMode ? 'Modifier la réservation' : 'Nouvelle réservation' }}
        </h3>
        <button class="btn btn-ghost btn-icon-sm" @click="emit('close')">
          <i class="ti ti-x" aria-hidden="true"></i>
        </button>
      </div>

      <!-- Erreur -->
      <div v-if="formError" style="background:var(--pms-red-light); color:var(--pms-red); padding:10px 14px; border-radius:var(--radius-md); margin-bottom:1rem; font-size:13px;">
        <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ formError }}
      </div>

      <!-- Dates -->
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Arrivée *</label>
          <input v-model="checkIn" type="date" class="input" @change="searchRooms()" />
        </div>
        <div class="input-wrap">
          <label class="input-label">Départ *</label>
          <input v-model="checkOut" type="date" class="input" @change="searchRooms()" />
        </div>
      </div>

      <!-- Adultes / Enfants -->
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
        <div class="input-wrap">
          <label class="input-label">Adultes</label>
          <input v-model.number="adults" type="number" min="1" max="10" class="input" @change="searchRooms()" />
        </div>
        <div class="input-wrap">
          <label class="input-label">Enfants</label>
          <input v-model.number="children" type="number" min="0" max="10" class="input" />
        </div>
      </div>

      <!-- Chambre -->
      <div class="input-wrap" style="margin-bottom:1rem;">
        <label class="input-label">Chambre *</label>
        <div v-if="loadingRooms" class="t-muted" style="font-size:12px;">Recherche des chambres disponibles...</div>
        <select v-model="roomId" class="select" :disabled="availableRooms.length === 0">
          <option value="">Sélectionnez une chambre</option>
          <option v-for="room in availableRooms" :key="room.id" :value="room.id">
            {{ room.number }} — {{ room.type.name }} ({{ formatCurrency(room.type.baseRateXof, { perNight: true }) }})
          </option>
        </select>
        <span v-if="checkIn && checkOut && availableRooms.length === 0 && !loadingRooms" class="input-hint error">
          Aucune chambre disponible pour ces dates
        </span>
      </div>

      <!-- Client -->
      <div class="input-wrap" style="margin-bottom:1rem; position:relative;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
          <label class="input-label" style="margin-bottom:0;">Client *</label>
          <button
            v-if="!showNewGuestForm"
            class="btn btn-ghost"
            style="padding:0; height:auto; font-size:12px; color:var(--pms-teal);"
            @click="openNewGuestForm()"
          >
            <i class="ti ti-plus" style="font-size:14px;"></i> Nouveau client
          </button>
          <button
            v-else
            class="btn btn-ghost"
            style="padding:0; height:auto; font-size:12px; color:var(--pms-ink-3);"
            @click="showNewGuestForm = false"
          >
            <i class="ti ti-search" style="font-size:14px;"></i> Rechercher
          </button>
        </div>

        <!-- Mode recherche -->
        <template v-if="!showNewGuestForm">
          <input
            v-model="guestQuery"
            class="input"
            placeholder="Rechercher par nom, email..."
            @input="onGuestQueryInput()"
          />
          <div v-if="guestId" style="margin-top:4px; display:flex; align-items:center; gap:4px; font-size:12px; color:var(--pms-green);">
            <i class="ti ti-circle-check"></i> Client sélectionné
          </div>
          <div v-else-if="guestQuery.length >= 2 && guests.length === 0"
               style="margin-top:4px; font-size:12px; color:var(--pms-ink-3);">
            Aucun client trouvé.
            <button
              style="background:none; border:none; color:var(--pms-teal); cursor:pointer; font-size:12px; text-decoration:underline; padding:0;"
              @click="openNewGuestForm()"
            >Créer un nouveau client</button>
          </div>
          <!-- Dropdown résultats -->
          <div
            v-if="guests.length > 0"
            style="position:absolute; top:100%; left:0; right:0; background:#fff; border:0.5px solid var(--pms-border-2); border-radius:var(--radius-md); z-index:10; max-height:200px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.08);"
          >
            <button
              v-for="g in guests"
              :key="g.id"
              style="display:flex; align-items:center; gap:8px; width:100%; padding:8px 12px; border:none; background:none; cursor:pointer; text-align:left; font-size:13px;"
              @click="selectGuest(g)"
            >
              <div class="avatar avatar-sm avatar-teal">
                {{ (g.firstName[0] + g.lastName[0]).toUpperCase() }}
              </div>
              <div>
                <div style="font-weight:500;">{{ g.firstName }} {{ g.lastName }}</div>
                <div class="t-muted" style="font-size:11px;">{{ g.email || g.phone || '' }}</div>
              </div>
            </button>
          </div>
        </template>

        <!-- Mode création -->
        <template v-else>
          <div v-if="newGuestError" style="background:var(--pms-red-light); color:var(--pms-red); padding:8px 12px; border-radius:var(--radius-md); margin-bottom:8px; font-size:12px;">
            <i class="ti ti-alert-circle"></i> {{ newGuestError }}
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px;">
            <input v-model="newGuest.firstName" class="input" placeholder="Prénom *" />
            <input v-model="newGuest.lastName" class="input" placeholder="Nom *" />
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px;">
            <input v-model="newGuest.email" class="input" type="email" placeholder="Email (optionnel)" />
            <input v-model="newGuest.phone" class="input" placeholder="Téléphone (optionnel)" />
          </div>
          <button class="btn btn-secondary btn-sm" :disabled="creatingGuest" @click="createGuest()">
            <span v-if="creatingGuest" class="spinner" style="width:12px; height:12px; border-width:1.5px;"></span>
            <template v-else><i class="ti ti-check"></i> Enregistrer le client</template>
          </button>
        </template>
      </div>

      <!-- Source -->
      <div class="input-wrap" style="margin-bottom:1rem;">
        <label class="input-label">Source</label>
        <select v-model="source" class="select">
          <option value="direct">Réservation directe</option>
          <option value="booking_com">Booking.com</option>
          <option value="airbnb">Airbnb</option>
          <option value="expedia">Expedia</option>
          <option value="walk_in">Sans réservation</option>
        </select>
      </div>

      <!-- Notes -->
      <div class="input-wrap" style="margin-bottom:1rem;">
        <label class="input-label">Notes (optionnel)</label>
        <input v-model="notes" class="input" placeholder="Notes internes..." />
      </div>

      <div class="input-wrap" style="margin-bottom:1rem;">
        <label class="input-label">Demandes spéciales (optionnel)</label>
        <input v-model="specialRequests" class="input" placeholder="Étage élevé, lit bébé..." />
      </div>

      <!-- Résumé du prix -->
      <div v-if="selectedRoom && nightsCount > 0" class="card-sand" style="padding:1rem; margin-bottom:1rem;">
        <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
          <span class="t-muted">{{ formatCurrency(selectedRoom.type.baseRateXof) }} x {{ nightsCount }} nuit{{ nightsCount > 1 ? 's' : '' }}</span>
          <span style="font-weight:500;">{{ formatCurrency(liveTotalRaw) }}</span>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:15px; font-weight:500; color:var(--pms-ink);">
          <span>Total séjour</span>
          <span>{{ formatCurrency(liveTotalRaw) }}</span>
        </div>
      </div>

      <!-- Actions -->
      <div style="display:flex; gap:8px; justify-content:flex-end;">
        <button class="btn btn-ghost" @click="emit('close')">Annuler</button>
        <button class="btn btn-primary" :disabled="submitting" @click="submit()">
          <span v-if="submitting" class="spinner" style="width:14px; height:14px; border-width:1.5px;"></span>
          <template v-else>
            <i :class="isEditMode ? 'ti ti-check' : 'ti ti-plus'" aria-hidden="true"></i>
            {{ isEditMode ? 'Enregistrer' : 'Créer la réservation' }}
          </template>
        </button>
      </div>

    </div>
  </div>
</template>
