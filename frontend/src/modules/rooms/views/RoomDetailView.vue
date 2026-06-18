<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { roomService, roomTypeService } from '@/services/room.service'
import type { Room, RoomType, RoomStatus } from '@/types/entities'
import { formatCurrency } from '@/utils/currency'

const route  = useRoute()
const router = useRouter()

// ── Data ──

const room      = ref<Room | null>(null)
const roomTypes = ref<RoomType[]>([])
const loading   = ref(true)
const error     = ref<string | null>(null)

// ── Room edit form ──

const editNumber   = ref('')
const editNotes    = ref('')
const editTypeId   = ref('')
const editIsActive = ref(true)
const savingRoom   = ref(false)
const roomSuccess  = ref(false)
const roomError    = ref<string | null>(null)

// ── Type edit form ──

const editTypeName        = ref('')
const editTypeRate        = ref('')
const editTypeOccupancy   = ref<number>(1)
const savingType          = ref(false)
const typeSuccess         = ref(false)
const typeError           = ref<string | null>(null)

// ── Load ──

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [r, types] = await Promise.all([
      roomService.getOne(route.params.id as string),
      roomTypeService.getAll(),
    ])
    room.value      = r
    roomTypes.value = types
    fillRoomForm(r)
    fillTypeForm(r.type)
  } catch {
    error.value = 'Chambre introuvable'
  } finally {
    loading.value = false
  }
}

function fillRoomForm(r: Room): void {
  editNumber.value   = r.number
  editNotes.value    = r.notes ?? ''
  editTypeId.value   = r.type.id
  editIsActive.value = r.isActive
}

function fillTypeForm(t: RoomType): void {
  editTypeName.value      = t.name
  editTypeRate.value      = t.baseRateXof
  editTypeOccupancy.value = t.maxOccupancy
}

// ── Save room ──

async function saveRoom(): Promise<void> {
  if (!room.value) return
  savingRoom.value  = true
  roomError.value   = null
  roomSuccess.value = false

  try {
    const updated = await roomService.update(room.value.id, {
      number:   editNumber.value,
      typeId:   editTypeId.value,
      notes:    editNotes.value || null,
      isActive: editIsActive.value,
    })
    room.value = updated
    fillRoomForm(updated)
    roomSuccess.value = true
    setTimeout(() => { roomSuccess.value = false }, 3000)
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'response' in e) {
      const err = e as { response: { data: { error?: string } } }
      roomError.value = err.response?.data?.error ?? 'Erreur lors de la sauvegarde'
    } else {
      roomError.value = 'Erreur lors de la sauvegarde'
    }
  } finally {
    savingRoom.value = false
  }
}

// ── Save type ──

async function saveType(): Promise<void> {
  if (!room.value) return

  const typeName = room.value.type.name
  if (!window.confirm(`Modifier le prix ou la capacité affecte TOUTES les chambres de type "${typeName}". Continuer ?`)) {
    return
  }

  savingType.value  = true
  typeError.value   = null
  typeSuccess.value = false

  try {
    const updated = await roomTypeService.update(room.value.type.id, {
      name:         editTypeName.value,
      baseRateXof:  editTypeRate.value,
      maxOccupancy: editTypeOccupancy.value,
    })
    room.value.type = updated
    fillTypeForm(updated)
    typeSuccess.value = true
    setTimeout(() => { typeSuccess.value = false }, 3000)
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'response' in e) {
      const err = e as { response: { data: { error?: string } } }
      typeError.value = err.response?.data?.error ?? 'Erreur lors de la sauvegarde'
    } else {
      typeError.value = 'Erreur lors de la sauvegarde'
    }
  } finally {
    savingType.value = false
  }
}

// ── Helpers ──

const statusLabel: Record<RoomStatus, string> = {
  available:    'Disponible',
  occupied:     'Occupée',
  cleaning:     'Ménage',
  maintenance:  'Maintenance',
  out_of_order: 'Hors service',
}

const statusBadgeClass: Record<RoomStatus, string> = {
  available:    'badge-available',
  occupied:     'badge-occupied',
  cleaning:     'badge-cleaning',
  maintenance:  'badge-maintenance',
  out_of_order: 'badge-maintenance',
}

onMounted(load)
</script>

<template>
  <div style="padding:1.5rem; max-width:900px; margin:0 auto;">
    <button class="btn btn-ghost btn-sm" style="margin-bottom:1rem;" @click="router.push('/rooms')">
      <i class="ti ti-arrow-left"></i> Retour aux chambres
    </button>

    <!-- Chargement -->
    <div v-if="loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>

    <!-- Erreur -->
    <div v-else-if="error" class="empty-state">
      <i class="ti ti-alert-circle"></i>
      <div>{{ error }}</div>
      <button class="btn btn-secondary btn-sm" @click="router.push('/rooms')">Retour</button>
    </div>

    <div v-else-if="room">
      <!-- En-tete -->
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem;">
        <div>
          <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
            Chambre <span class="t-mono">{{ room.number }}</span>
          </h1>
          <p class="t-muted">{{ room.type.name }} · {{ room.floor ? `Étage ${room.floor.number}` : 'Sans étage' }} · {{ formatCurrency(room.type.baseRateXof, { perNight: true }) }}</p>
        </div>
        <span :class="['badge', statusBadgeClass[room.status]]">
          <span class="badge-dot"></span>{{ statusLabel[room.status] }}
        </span>
      </div>

      <!-- ── Form chambre ── -->
      <div class="card" style="padding:1.25rem; margin-bottom:1rem;">
        <div style="font-size:11px; font-weight:500; color:var(--pms-ink-3); letter-spacing:0.04em; text-transform:uppercase; margin-bottom:12px;">Informations chambre</div>

        <div v-if="roomError" style="background:var(--pms-red-light); color:var(--pms-red); padding:8px 12px; border-radius:var(--radius-md); margin-bottom:12px; font-size:12px;">
          <i class="ti ti-alert-circle"></i> {{ roomError }}
        </div>
        <div v-if="roomSuccess" style="background:var(--pms-green-light); color:var(--pms-green); padding:8px 12px; border-radius:var(--radius-md); margin-bottom:12px; font-size:12px;">
          <i class="ti ti-circle-check"></i> Chambre mise à jour
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
          <div class="input-wrap">
            <label class="input-label">Numéro</label>
            <input v-model="editNumber" class="input" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Type</label>
            <select v-model="editTypeId" class="select">
              <option v-for="t in roomTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
          </div>
        </div>

        <div class="input-wrap" style="margin-bottom:12px;">
          <label class="input-label">Notes</label>
          <input v-model="editNotes" class="input" placeholder="Notes internes..." />
        </div>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
          <label class="input-label" style="margin-bottom:0;">Active</label>
          <button
            :class="['toggle', editIsActive ? 'on' : '']"
            @click="editIsActive = !editIsActive"
          ></button>
        </div>

        <div style="display:flex; justify-content:flex-end;">
          <button class="btn btn-primary btn-sm" :disabled="savingRoom" @click="saveRoom()">
            <span v-if="savingRoom" class="spinner" style="width:12px; height:12px; border-width:1.5px;"></span>
            <template v-else><i class="ti ti-check"></i> Enregistrer</template>
          </button>
        </div>
      </div>

      <!-- ── Form type ── -->
      <div class="card" style="padding:1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <div style="font-size:11px; font-weight:500; color:var(--pms-ink-3); letter-spacing:0.04em; text-transform:uppercase;">Type : {{ room.type.name }}</div>
        </div>

        <!-- Avertissement -->
        <div style="background:var(--pms-gold-light); color:var(--pms-gold-dark); padding:10px 14px; border-radius:var(--radius-md); margin-bottom:12px; font-size:12px; display:flex; align-items:flex-start; gap:8px;">
          <i class="ti ti-alert-triangle" style="font-size:16px; flex-shrink:0; margin-top:1px;"></i>
          <span>Modifier le prix ou la capacité affecte <strong>toutes les chambres</strong> de type <strong>{{ room.type.name }}</strong>.</span>
        </div>

        <div v-if="typeError" style="background:var(--pms-red-light); color:var(--pms-red); padding:8px 12px; border-radius:var(--radius-md); margin-bottom:12px; font-size:12px;">
          <i class="ti ti-alert-circle"></i> {{ typeError }}
        </div>
        <div v-if="typeSuccess" style="background:var(--pms-green-light); color:var(--pms-green); padding:8px 12px; border-radius:var(--radius-md); margin-bottom:12px; font-size:12px;">
          <i class="ti ti-circle-check"></i> Type mis à jour
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:12px;">
          <div class="input-wrap">
            <label class="input-label">Nom du type</label>
            <input v-model="editTypeName" class="input" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Prix / nuit (FCFA)</label>
            <input v-model="editTypeRate" class="input" type="text" inputmode="numeric" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Capacite max</label>
            <input v-model.number="editTypeOccupancy" class="input" type="number" min="1" max="20" />
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end;">
          <button class="btn btn-primary btn-sm" :disabled="savingType" @click="saveType()">
            <span v-if="savingType" class="spinner" style="width:12px; height:12px; border-width:1.5px;"></span>
            <template v-else><i class="ti ti-check"></i> Enregistrer le type</template>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.badge-available   { background: var(--pms-green-light); color: var(--pms-green); }
.badge-available .badge-dot { background: var(--pms-green); }
.badge-occupied    { background: var(--pms-red-light); color: var(--pms-red); }
.badge-occupied .badge-dot { background: var(--pms-red); }
.badge-cleaning    { background: var(--pms-gold-light); color: var(--pms-gold-dark); }
.badge-cleaning .badge-dot { background: var(--pms-gold); }
.badge-maintenance { background: var(--pms-blue-light); color: var(--pms-blue); }
.badge-maintenance .badge-dot { background: var(--pms-blue); }
</style>
