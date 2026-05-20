<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { guestService } from '@/services/guest.service'
import { formatCurrency } from '@/utils/currency'
import type { Guest, Reservation, ReservationStatus } from '@/types/entities'

const route  = useRoute()
const router = useRouter()

// ── Data ──

const guest   = ref<Guest | null>(null)
const stays   = ref<Reservation[]>([])
const loading = ref(true)
const error   = ref<string | null>(null)

// ── Edit form ──

const editing      = ref(false)
const saving       = ref(false)
const saveSuccess  = ref(false)
const saveError    = ref<string | null>(null)

const editFirstName      = ref('')
const editLastName       = ref('')
const editEmail          = ref('')
const editPhone          = ref('')
const editNationality    = ref('')
const editDocumentType   = ref('')
const editDocumentNumber = ref('')
const editDocumentUrl    = ref('')
const editAddress        = ref('')
const editCity           = ref('')
const editCountry        = ref('')

// ── Load ──

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const id = route.params.id as string
    const [g, s] = await Promise.all([
      guestService.getOne(id),
      guestService.stays(id),
    ])
    guest.value = g
    stays.value = s
    fillForm(g)
    if (route.query.edit === '1') {
      editing.value = true
    }
  } catch {
    error.value = 'Client introuvable'
  } finally {
    loading.value = false
  }
}

function fillForm(g: Guest): void {
  editFirstName.value      = g.firstName
  editLastName.value       = g.lastName
  editEmail.value          = g.email ?? ''
  editPhone.value          = g.phone ?? ''
  editNationality.value    = g.nationality ?? ''
  editDocumentType.value   = g.documentType ?? ''
  editDocumentNumber.value = g.documentNumber ?? ''
  editDocumentUrl.value    = g.documentUrl ?? ''
  editAddress.value        = g.address ?? ''
  editCity.value           = g.city ?? ''
  editCountry.value        = g.country ?? ''
}

function startEdit(): void {
  if (guest.value) fillForm(guest.value)
  editing.value = true
  saveError.value = null
  saveSuccess.value = false
}

function cancelEdit(): void {
  editing.value = false
  saveError.value = null
}

// ── Save ──

async function saveGuest(): Promise<void> {
  if (!guest.value) return
  saving.value    = true
  saveError.value = null
  saveSuccess.value = false

  try {
    const updated = await guestService.update(guest.value.id, {
      firstName:      editFirstName.value,
      lastName:       editLastName.value,
      email:          editEmail.value || null,
      phone:          editPhone.value || null,
      nationality:    editNationality.value || null,
      documentType:   editDocumentType.value || null,
      documentNumber: editDocumentNumber.value || null,
      documentUrl:    editDocumentUrl.value || null,
      address:        editAddress.value || null,
      city:           editCity.value || null,
      country:        editCountry.value || null,
    })
    guest.value = updated
    editing.value = false
    saveSuccess.value = true
    setTimeout(() => { saveSuccess.value = false }, 3000)
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'response' in e) {
      const err = e as { response: { data: { error?: string } } }
      saveError.value = err.response?.data?.error ?? 'Erreur lors de la sauvegarde'
    } else {
      saveError.value = 'Erreur lors de la sauvegarde'
    }
  } finally {
    saving.value = false
  }
}

// ── Helpers ──

const documentTypeLabels: Record<string, string> = {
  PASSPORT:         'Passeport',
  ID_CARD:          'CNI',
  RESIDENCE_PERMIT: 'Permis de séjour',
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

function formatDate(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleDateString('fr-SN', { day: '2-digit', month: 'short', year: 'numeric' })
}

function nights(checkIn: string, checkOut: string): number {
  const ci = new Date(checkIn)
  const co = new Date(checkOut)
  return Math.round((co.getTime() - ci.getTime()) / (1000 * 60 * 60 * 24))
}

onMounted(load)
</script>

<template>
  <div style="padding:1.5rem; max-width:900px; margin:0 auto;">
    <button class="btn btn-ghost btn-sm" style="margin-bottom:1rem;" @click="router.push('/guests')">
      <i class="ti ti-arrow-left"></i> Retour aux clients
    </button>

    <!-- Chargement -->
    <div v-if="loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>

    <!-- Erreur -->
    <div v-else-if="error" class="empty-state">
      <i class="ti ti-alert-circle"></i>
      <div>{{ error }}</div>
      <button class="btn btn-secondary btn-sm" @click="router.push('/guests')">Retour</button>
    </div>

    <div v-else-if="guest">

      <!-- En-tete -->
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div class="avatar avatar-lg avatar-teal">
            {{ (guest.firstName[0] + guest.lastName[0]).toUpperCase() }}
          </div>
          <div>
            <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
              {{ guest.firstName }} {{ guest.lastName }}
            </h1>
            <p class="t-muted">{{ guest.email || '—' }} · {{ guest.phone || '—' }}</p>
          </div>
        </div>
        <button v-if="!editing" class="btn btn-secondary" @click="startEdit()">
          <i class="ti ti-edit"></i> Modifier
        </button>
      </div>

      <!-- Messages -->
      <div v-if="saveSuccess" style="background:var(--pms-green-light); color:var(--pms-green); padding:8px 12px; border-radius:var(--radius-md); margin-bottom:12px; font-size:12px;">
        <i class="ti ti-circle-check"></i> Client mis à jour
      </div>

      <!-- ── Mode lecture ── -->
      <div v-if="!editing" class="card" style="padding:1.25rem; margin-bottom:1rem;">
        <div style="font-size:11px; font-weight:500; color:var(--pms-ink-3); letter-spacing:0.04em; text-transform:uppercase; margin-bottom:12px;">Informations</div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
          <div>
            <div class="t-muted" style="font-size:12px;">Nationalité</div>
            <div>{{ guest.nationality || '—' }}</div>
          </div>
          <div>
            <div class="t-muted" style="font-size:12px;">Document</div>
            <div>
              {{ documentTypeLabels[guest.documentType ?? ''] || guest.documentType || '—' }}
              <span v-if="guest.documentNumber" class="t-mono" style="margin-left:4px;">{{ guest.documentNumber }}</span>
            </div>
          </div>
          <div v-if="guest.documentUrl">
            <div class="t-muted" style="font-size:12px;">Document (lien)</div>
            <div>
              <a :href="guest.documentUrl" target="_blank" rel="noopener" style="color:var(--pms-teal); font-size:13px;">
                <i class="ti ti-external-link" style="font-size:14px;"></i> Voir le document
              </a>
            </div>
          </div>
          <div>
            <div class="t-muted" style="font-size:12px;">Adresse</div>
            <div>{{ [guest.address, guest.city, guest.country].filter(Boolean).join(', ') || '—' }}</div>
          </div>
          <div>
            <div class="t-muted" style="font-size:12px;">Séjours</div>
            <div style="font-weight:500;">{{ guest.totalStays ?? 0 }}</div>
          </div>
        </div>
      </div>

      <!-- ── Mode edition ── -->
      <div v-else class="card" style="padding:1.25rem; margin-bottom:1rem;">
        <div style="font-size:11px; font-weight:500; color:var(--pms-ink-3); letter-spacing:0.04em; text-transform:uppercase; margin-bottom:12px;">Modifier le client</div>

        <div v-if="saveError" style="background:var(--pms-red-light); color:var(--pms-red); padding:8px 12px; border-radius:var(--radius-md); margin-bottom:12px; font-size:12px;">
          <i class="ti ti-alert-circle"></i> {{ saveError }}
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
          <div class="input-wrap">
            <label class="input-label">Prénom</label>
            <input v-model="editFirstName" class="input" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Nom</label>
            <input v-model="editLastName" class="input" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Email</label>
            <input v-model="editEmail" class="input" type="email" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Téléphone</label>
            <input v-model="editPhone" class="input" type="tel" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Nationalité (ISO-2)</label>
            <input v-model="editNationality" class="input" maxlength="2" placeholder="SN" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Type de document</label>
            <select v-model="editDocumentType" class="select">
              <option value="">—</option>
              <option value="ID_CARD">CNI</option>
              <option value="PASSPORT">Passeport</option>
              <option value="RESIDENCE_PERMIT">Permis de séjour</option>
            </select>
          </div>
          <div class="input-wrap">
            <label class="input-label">Numéro de document</label>
            <input v-model="editDocumentNumber" class="input" />
          </div>
          <div class="input-wrap">
            <label class="input-label">URL du document</label>
            <input v-model="editDocumentUrl" class="input" type="url" placeholder="https://..." />
            <span class="input-hint">URL complète (https://...)</span>
          </div>
          <div class="input-wrap">
            <label class="input-label">Adresse</label>
            <input v-model="editAddress" class="input" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Ville</label>
            <input v-model="editCity" class="input" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Pays (ISO-2)</label>
            <input v-model="editCountry" class="input" maxlength="2" placeholder="SN" />
          </div>
        </div>

        <div style="display:flex; gap:8px; justify-content:flex-end;">
          <button class="btn btn-ghost btn-sm" @click="cancelEdit()">Annuler</button>
          <button class="btn btn-primary btn-sm" :disabled="saving" @click="saveGuest()">
            <span v-if="saving" class="spinner" style="width:12px; height:12px; border-width:1.5px;"></span>
            <template v-else><i class="ti ti-check"></i> Enregistrer</template>
          </button>
        </div>
      </div>

      <!-- ── Historique des sejours ── -->
      <div class="card" style="padding:1.25rem;">
        <div style="font-size:11px; font-weight:500; color:var(--pms-ink-3); letter-spacing:0.04em; text-transform:uppercase; margin-bottom:12px;">
          Historique des séjours
        </div>

        <div v-if="stays.length === 0" class="t-muted" style="padding:1rem 0; text-align:center; font-size:13px;">
          Aucun séjour enregistré
        </div>

        <div v-else class="table-wrap" style="border:none;">
          <table>
            <thead>
              <tr>
                <th>Référence</th>
                <th>Chambre</th>
                <th>Arrivée</th>
                <th>Départ</th>
                <th>Nuits</th>
                <th>Total</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="r in stays"
                :key="r.id"
                style="cursor:pointer;"
                @click="router.push(`/reservations/${r.id}`)"
              >
                <td><span class="t-mono">{{ r.confirmationNumber }}</span></td>
                <td><span class="t-mono">{{ r.room.number }}</span></td>
                <td>{{ formatDate(r.checkIn) }}</td>
                <td>{{ formatDate(r.checkOut) }}</td>
                <td>{{ nights(r.checkIn, r.checkOut) }}</td>
                <td style="font-weight:500;">{{ formatCurrency(r.totalXof) }}</td>
                <td>
                  <span :class="['badge', statusBadgeClass[r.status]]">
                    <span class="badge-dot"></span>{{ statusLabels[r.status] }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>
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
