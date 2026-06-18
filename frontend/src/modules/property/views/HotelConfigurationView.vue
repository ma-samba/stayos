<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useNotificationsStore } from '@/stores/notifications.store'
import { floorService, roomTypeService, roomService } from '@/services/room.service'
import { tenantSettingsService } from '@/services/tenant-settings.service'
import type { Floor, Room, RoomType, RoomUsage } from '@/types/entities'
import type {
  CancellationPolicy,
  NoShowPolicy,
  TenantSettings,
} from '@/types/financial-policies'
import BulkCreateRoomsModal from '@/modules/property/components/BulkCreateRoomsModal.vue'

// ──────────────────────────────────────────────────────────────
//  HotelConfigurationView — Sprint 13ter + 14-A.2
//  4 onglets : Étages / Types de chambre / Chambres / Finances
//  Réservé aux managers (RBAC géré côté router).
// ──────────────────────────────────────────────────────────────

type Tab = 'floors' | 'types' | 'rooms' | 'finances'
const activeTab = ref<Tab>('floors')

const notif = useNotificationsStore()

// ── State ─────────────────────────────────────────────────────
const floors    = ref<Floor[]>([])
const types     = ref<RoomType[]>([])
const rooms     = ref<Room[]>([])
const usage     = ref<RoomUsage | null>(null)
const loading   = ref(false)

// ── Onglet Étages ─────────────────────────────────────────────
const newFloor = ref<{ number: number | null; name: string }>({ number: null, name: '' })
const editingFloorId = ref<string | null>(null)
const editingFloorDraft = ref<{ number: number; name: string }>({ number: 0, name: '' })

async function refreshFloors(): Promise<void> {
  floors.value = await floorService.getAll()
}

async function createFloor(): Promise<void> {
  if (newFloor.value.number === null || isNaN(newFloor.value.number)) {
    notif.pushUiToast('alert', 'Numéro d\'étage requis.')
    return
  }
  try {
    await floorService.create({
      number: newFloor.value.number,
      name: newFloor.value.name?.trim() || null,
    })
    newFloor.value = { number: null, name: '' }
    await refreshFloors()
    notif.pushUiToast('success', 'Étage créé.')
  } catch (e: unknown) {
    notif.pushUiToast('alert', extractError(e, 'Création impossible.'))
  }
}

function startEditFloor(f: Floor): void {
  editingFloorId.value = f.id
  editingFloorDraft.value = { number: f.number, name: f.name ?? '' }
}

async function saveFloor(f: Floor): Promise<void> {
  try {
    await floorService.update(f.id, {
      number: editingFloorDraft.value.number,
      name: editingFloorDraft.value.name?.trim() || null,
    })
    editingFloorId.value = null
    await refreshFloors()
    notif.pushUiToast('success', 'Étage mis à jour.')
  } catch (e: unknown) {
    notif.pushUiToast('alert', extractError(e, 'Mise à jour impossible.'))
  }
}

async function deleteFloor(f: Floor): Promise<void> {
  if (!confirm(`Supprimer l'étage ${f.number}${f.name ? ' — ' + f.name : ''} ?`)) return
  try {
    await floorService.delete(f.id)
    await refreshFloors()
    notif.pushUiToast('success', 'Étage supprimé.')
  } catch (e: unknown) {
    notif.pushUiToast('alert', extractError(e, 'Suppression impossible.'))
  }
}

// ── Onglet Types ──────────────────────────────────────────────
const editingType = ref<RoomType | null>(null)
const typeDraft = ref<{
  name: string
  description: string
  baseRateXof: string
  maxOccupancy: number
  sortOrder: number
}>({ name: '', description: '', baseRateXof: '', maxOccupancy: 2, sortOrder: 0 })
const showTypeModal = ref(false)

async function refreshTypes(): Promise<void> {
  types.value = await roomTypeService.getAll()
}

function openTypeModal(t: RoomType | null): void {
  editingType.value = t
  if (t) {
    typeDraft.value = {
      name: t.name,
      description: t.description ?? '',
      baseRateXof: t.baseRateXof,
      maxOccupancy: t.maxOccupancy,
      sortOrder: t.sortOrder ?? 0,
    }
  } else {
    typeDraft.value = { name: '', description: '', baseRateXof: '', maxOccupancy: 2, sortOrder: types.value.length }
  }
  showTypeModal.value = true
}

async function saveType(): Promise<void> {
  try {
    const payload = {
      name: typeDraft.value.name.trim(),
      description: typeDraft.value.description.trim() || null,
      baseRateXof: typeDraft.value.baseRateXof,
      maxOccupancy: typeDraft.value.maxOccupancy,
      sortOrder: typeDraft.value.sortOrder,
    }
    if (editingType.value) {
      await roomTypeService.update(editingType.value.id, payload)
      notif.pushUiToast('success', 'Type mis à jour.')
    } else {
      await roomTypeService.create(payload)
      notif.pushUiToast('success', 'Type créé.')
    }
    showTypeModal.value = false
    await refreshTypes()
  } catch (e: unknown) {
    notif.pushUiToast('alert', extractError(e, 'Enregistrement impossible.'))
  }
}

async function deleteType(t: RoomType): Promise<void> {
  if (!confirm(`Supprimer le type « ${t.name} » ?`)) return
  try {
    await roomTypeService.delete(t.id)
    await refreshTypes()
    notif.pushUiToast('success', 'Type supprimé.')
  } catch (e: unknown) {
    notif.pushUiToast('alert', extractError(e, 'Suppression impossible.'))
  }
}

// ── Onglet Chambres ───────────────────────────────────────────
const showCreateRoomModal = ref(false)
const showEditRoomModal = ref(false)
const showBulkModal = ref(false)
const newRoom = ref<{
  number: string
  typeId: string
  floorId: string
  notes: string
}>({ number: '', typeId: '', floorId: '', notes: '' })
const editingRoom = ref<Room | null>(null)
const editRoomDraft = ref<{
  number: string
  typeId: string
  floorId: string
  notes: string
}>({ number: '', typeId: '', floorId: '', notes: '' })
const filterTypeId = ref<string>('')
const filterFloorId = ref<string>('')
const filterActive = ref<'all' | 'active' | 'inactive'>('all')

const filteredRooms = computed(() => rooms.value.filter(r => {
  if (filterTypeId.value && r.type.id !== filterTypeId.value) return false
  if (filterFloorId.value && (r.floor?.id ?? '') !== filterFloorId.value) return false
  if (filterActive.value === 'active' && !r.isActive) return false
  if (filterActive.value === 'inactive' && r.isActive) return false
  return true
}))

async function refreshRooms(): Promise<void> {
  rooms.value = await roomService.getAll()
  usage.value = await roomService.getUsage()
}

function openCreateRoomModal(): void {
  newRoom.value = {
    number: '',
    typeId: types.value[0]?.id ?? '',
    floorId: floors.value[0]?.id ?? '',
    notes: '',
  }
  showCreateRoomModal.value = true
}

async function createRoom(): Promise<void> {
  if (!newRoom.value.number.trim() || !newRoom.value.typeId) {
    notif.pushUiToast('alert', 'Numéro et type requis.')
    return
  }
  try {
    await roomService.create({
      number: newRoom.value.number.trim(),
      typeId: newRoom.value.typeId,
      floorId: newRoom.value.floorId || null,
      notes: newRoom.value.notes?.trim() || null,
    })
    showCreateRoomModal.value = false
    await refreshRooms()
    notif.pushUiToast('success', 'Chambre créée.')
  } catch (e: unknown) {
    notif.pushUiToast('alert', extractError(e, 'Création impossible.'))
  }
}

function openEditRoomModal(room: Room): void {
  editingRoom.value = room
  editRoomDraft.value = {
    number:  room.number,
    typeId:  room.type.id,
    floorId: room.floor?.id ?? '',
    notes:   room.notes ?? '',
  }
  showEditRoomModal.value = true
}

async function saveRoom(): Promise<void> {
  if (!editingRoom.value) return
  if (!editRoomDraft.value.number.trim() || !editRoomDraft.value.typeId) {
    notif.pushUiToast('alert', 'Numéro et type requis.')
    return
  }
  try {
    await roomService.update(editingRoom.value.id, {
      number:  editRoomDraft.value.number.trim(),
      typeId:  editRoomDraft.value.typeId,
      floorId: editRoomDraft.value.floorId || null,
      notes:   editRoomDraft.value.notes.trim() || null,
    })
    showEditRoomModal.value = false
    editingRoom.value = null
    await refreshRooms()
    notif.pushUiToast('success', 'Chambre modifiée.')
  } catch (e: unknown) {
    notif.pushUiToast('alert', extractError(e, 'Modification impossible.'))
  }
}

async function softDeleteRoom(r: Room): Promise<void> {
  if (!confirm(`Désactiver la chambre ${r.number} ? Elle pourra être réactivée plus tard.`)) return
  try {
    await roomService.softDelete(r.id)
    await refreshRooms()
    notif.pushUiToast('success', 'Chambre désactivée.')
  } catch (e: unknown) {
    notif.pushUiToast('alert', extractError(e, 'Suppression impossible.'))
  }
}

async function reactivateRoom(r: Room): Promise<void> {
  try {
    await roomService.reactivate(r.id)
    await refreshRooms()
    notif.pushUiToast('success', 'Chambre réactivée.')
  } catch (e: unknown) {
    notif.pushUiToast('alert', extractError(e, 'Réactivation impossible.'))
  }
}

async function handleBulkCreated(): Promise<void> {
  showBulkModal.value = false
  await refreshRooms()
}

// ── Onglet Finances (Sprint 14-A.2) ───────────────────────────
const financeSettings = ref<TenantSettings | null>(null)
const financeDraft    = ref<{
  noShowPolicy: NoShowPolicy
  cancellationPolicy: CancellationPolicy
  businessDayCutoffHour: number
} | null>(null)
const financeSaving = ref(false)
const financeError  = ref<string | null>(null)

const financeHasChanges = computed<boolean>(() => {
  if (!financeSettings.value || !financeDraft.value) return false
  return (
    financeDraft.value.noShowPolicy          !== financeSettings.value.noShowPolicy ||
    financeDraft.value.cancellationPolicy    !== financeSettings.value.cancellationPolicy ||
    financeDraft.value.businessDayCutoffHour !== financeSettings.value.businessDayCutoffHour
  )
})

async function refreshFinanceSettings(): Promise<void> {
  try {
    const settings = await tenantSettingsService.get()
    financeSettings.value = settings
    financeDraft.value = {
      noShowPolicy:          settings.noShowPolicy,
      cancellationPolicy:    settings.cancellationPolicy,
      businessDayCutoffHour: settings.businessDayCutoffHour,
    }
    financeError.value = null
  } catch (e: unknown) {
    financeError.value = extractError(e, 'Chargement impossible.')
  }
}

function resetFinanceDraft(): void {
  if (financeSettings.value) {
    financeDraft.value = {
      noShowPolicy:          financeSettings.value.noShowPolicy,
      cancellationPolicy:    financeSettings.value.cancellationPolicy,
      businessDayCutoffHour: financeSettings.value.businessDayCutoffHour,
    }
  }
}

async function saveFinanceSettings(): Promise<void> {
  if (!financeDraft.value || !financeHasChanges.value) return
  financeSaving.value = true
  try {
    const updated = await tenantSettingsService.update({
      noShowPolicy:          financeDraft.value.noShowPolicy,
      cancellationPolicy:    financeDraft.value.cancellationPolicy,
      businessDayCutoffHour: financeDraft.value.businessDayCutoffHour,
    })
    financeSettings.value = updated
    // financeDraft reste aligné car le serveur renvoie les mêmes valeurs.
    notif.pushUiToast('success', 'Politiques financières mises à jour.')
  } catch (e: unknown) {
    notif.pushUiToast('alert', extractError(e, 'Mise à jour impossible.'))
  } finally {
    financeSaving.value = false
  }
}

// ── Utils ─────────────────────────────────────────────────────
function extractError(e: unknown, fallback: string): string {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const resp = (e as any)?.response?.data
  return resp?.error ?? fallback
}

function fmtXof(v: string): string {
  const n = Number(v)
  if (Number.isNaN(n)) return v
  return new Intl.NumberFormat('fr-FR').format(n) + ' XOF'
}

// ── Init ──────────────────────────────────────────────────────
onMounted(async () => {
  loading.value = true
  try {
    await Promise.all([
      refreshFloors(),
      refreshTypes(),
      refreshRooms(),
      refreshFinanceSettings(),
    ])
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="config-view">
    <header class="config-header">
      <h1>Configuration de l'hôtel</h1>
      <p class="t-muted">Étages, types de chambre et inventaire. Modifiable à tout moment.</p>
    </header>

    <nav class="tabs">
      <button class="tab" :class="{ active: activeTab === 'floors' }" @click="activeTab = 'floors'">
        Étages
      </button>
      <button class="tab" :class="{ active: activeTab === 'types' }" @click="activeTab = 'types'">
        Types de chambre
      </button>
      <button class="tab" :class="{ active: activeTab === 'rooms' }" @click="activeTab = 'rooms'">
        Chambres
      </button>
      <button class="tab" :class="{ active: activeTab === 'finances' }" @click="activeTab = 'finances'">
        Finances
      </button>
    </nav>

    <!-- ── Onglet Étages ── -->
    <section v-if="activeTab === 'floors'" class="card">
      <h2>Étages</h2>

      <form class="floor-create" @submit.prevent="createFloor">
        <input
          v-model.number="newFloor.number"
          class="input"
          type="number"
          placeholder="N°"
          style="width:90px;"
        />
        <input
          v-model="newFloor.name"
          class="input"
          placeholder="Nom (optionnel — ex: Rez-de-chaussée)"
          style="flex:1;"
        />
        <button class="btn btn-primary" type="submit">
          <i class="ti ti-plus" aria-hidden="true"></i> Ajouter
        </button>
      </form>

      <table v-if="floors.length > 0" class="data-table" style="margin-top:18px;">
        <thead>
          <tr><th>N°</th><th>Nom</th><th>Actif</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="f in floors" :key="f.id">
            <template v-if="editingFloorId === f.id">
              <td><input v-model.number="editingFloorDraft.number" class="input" type="number" style="width:80px;" /></td>
              <td><input v-model="editingFloorDraft.name" class="input" /></td>
              <td><span class="badge">{{ f.active === false ? 'Inactif' : 'Actif' }}</span></td>
              <td style="text-align:right;">
                <button class="btn btn-primary btn-sm" @click="saveFloor(f)">Enregistrer</button>
                <button class="btn btn-ghost btn-sm" @click="editingFloorId = null">Annuler</button>
              </td>
            </template>
            <template v-else>
              <td>{{ f.number }}</td>
              <td>{{ f.name || '—' }}</td>
              <td>
                <span class="badge" :class="f.active === false ? 'badge-muted' : 'badge-success'">
                  {{ f.active === false ? 'Inactif' : 'Actif' }}
                </span>
              </td>
              <td style="text-align:right;">
                <button class="btn btn-ghost btn-sm" @click="startEditFloor(f)">
                  <i class="ti ti-edit" aria-hidden="true"></i>
                </button>
                <button class="btn btn-ghost btn-sm" @click="deleteFloor(f)">
                  <i class="ti ti-trash" aria-hidden="true"></i>
                </button>
              </td>
            </template>
          </tr>
        </tbody>
      </table>
      <p v-else class="t-muted">Aucun étage. Commencez par en créer un — vous pourrez ensuite y rattacher des chambres.</p>
    </section>

    <!-- ── Onglet Types ── -->
    <section v-if="activeTab === 'types'" class="card">
      <header class="section-header">
        <h2>Types de chambre</h2>
        <button class="btn btn-primary" @click="openTypeModal(null)">
          <i class="ti ti-plus" aria-hidden="true"></i> Nouveau type
        </button>
      </header>

      <table v-if="types.length > 0" class="data-table">
        <thead>
          <tr><th>Nom</th><th>Description</th><th>Tarif</th><th>Capacité</th><th>Ordre</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="t in types" :key="t.id">
            <td><strong>{{ t.name }}</strong></td>
            <td class="t-muted">{{ t.description || '—' }}</td>
            <td>{{ fmtXof(t.baseRateXof) }}</td>
            <td>{{ t.maxOccupancy }}</td>
            <td>{{ t.sortOrder ?? '—' }}</td>
            <td style="text-align:right;">
              <button class="btn btn-ghost btn-sm" @click="openTypeModal(t)">
                <i class="ti ti-edit" aria-hidden="true"></i>
              </button>
              <button class="btn btn-ghost btn-sm" @click="deleteType(t)">
                <i class="ti ti-trash" aria-hidden="true"></i>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="t-muted">Aucun type de chambre. Créez-en un avant d'ajouter des chambres.</p>
    </section>

    <!-- ── Onglet Chambres ── -->
    <section v-if="activeTab === 'rooms'" class="card">
      <header class="section-header">
        <div>
          <h2>Chambres</h2>
          <p v-if="usage" class="usage-pill">
            <strong>{{ usage.used }}</strong> /
            {{ usage.max ?? '∞' }} chambres
            <span v-if="usage.plan" class="t-muted">— Plan {{ usage.plan }}</span>
          </p>
        </div>
        <div style="display:flex; gap:8px;">
          <button class="btn btn-secondary" @click="showBulkModal = true">
            <i class="ti ti-stack-2" aria-hidden="true"></i> Création en lot
          </button>
          <button class="btn btn-primary" @click="openCreateRoomModal">
            <i class="ti ti-plus" aria-hidden="true"></i> Ajouter une chambre
          </button>
        </div>
      </header>

      <div class="filters">
        <select v-model="filterFloorId" class="input">
          <option value="">Tous les étages</option>
          <option v-for="f in floors" :key="f.id" :value="f.id">{{ f.number }} — {{ f.name || 'Étage ' + f.number }}</option>
        </select>
        <select v-model="filterTypeId" class="input">
          <option value="">Tous les types</option>
          <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
        <select v-model="filterActive" class="input">
          <option value="all">Toutes</option>
          <option value="active">Actives</option>
          <option value="inactive">Désactivées</option>
        </select>
      </div>

      <table v-if="filteredRooms.length > 0" class="data-table">
        <thead>
          <tr><th>N°</th><th>Type</th><th>Étage</th><th>Statut</th><th>Active</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="r in filteredRooms" :key="r.id" :class="{ 'row-muted': !r.isActive }">
            <td><strong>{{ r.number }}</strong></td>
            <td>{{ r.type.name }}</td>
            <td>{{ r.floor ? (r.floor.name || 'Étage ' + r.floor.number) : '—' }}</td>
            <td><span class="badge">{{ r.status }}</span></td>
            <td>
              <span class="badge" :class="r.isActive ? 'badge-success' : 'badge-muted'">
                {{ r.isActive ? 'Active' : 'Désactivée' }}
              </span>
            </td>
            <td style="text-align:right;">
              <button
                v-if="r.isActive"
                class="btn btn-ghost btn-sm"
                title="Modifier"
                @click="openEditRoomModal(r)"
              >
                <i class="ti ti-edit" aria-hidden="true"></i>
              </button>
              <button v-if="r.isActive" class="btn btn-ghost btn-sm" @click="softDeleteRoom(r)">
                <i class="ti ti-trash" aria-hidden="true"></i>
              </button>
              <button v-else class="btn btn-secondary btn-sm" @click="reactivateRoom(r)">
                <i class="ti ti-rotate-clockwise" aria-hidden="true"></i> Réactiver
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="t-muted">Aucune chambre pour ces filtres.</p>
    </section>

    <!-- ── Onglet Finances (Sprint 14-A.2) ── -->
    <section v-if="activeTab === 'finances'" class="card">
      <header class="section-header">
        <div>
          <h2>Politiques financières</h2>
          <p class="t-muted">
            Ces paramètres pilotent le calcul automatique des frais de
            no-show et d'annulation, ainsi que l'heure de clôture comptable
            journalière.
          </p>
        </div>
      </header>

      <div v-if="financeError" class="error-box">
        <i class="ti ti-alert-circle" aria-hidden="true"></i>
        <span>{{ financeError }}</span>
        <button class="btn btn-secondary btn-sm" @click="refreshFinanceSettings">
          Réessayer
        </button>
      </div>

      <div v-else-if="financeDraft" class="finance-form">
        <div class="form-row">
          <label for="no-show-policy" class="input-label">Politique no-show</label>
          <select
            id="no-show-policy"
            v-model="financeDraft.noShowPolicy"
            class="input"
          >
            <option value="none">Aucun frais</option>
            <option value="first_night">Première nuit facturée (recommandé)</option>
            <option value="full">Séjour complet facturé</option>
          </select>
          <p class="hint">
            Appliquée quand un client est marqué absent (no-show). Le
            réceptionniste peut surcharger cas par cas.
          </p>
        </div>

        <div class="form-row">
          <label for="cancellation-policy" class="input-label">Politique d'annulation</label>
          <select
            id="cancellation-policy"
            v-model="financeDraft.cancellationPolicy"
            class="input"
          >
            <option value="flexible">Flexible (jamais de frais)</option>
            <option value="moderate">Modérée (selon délai)</option>
            <option value="strict">Stricte (toujours frais)</option>
          </select>
          <p class="hint">
            <strong>Flexible</strong>&nbsp;: aucun frais quel que soit le délai.
            <strong>Modérée</strong>&nbsp;: 0 si &gt; 48 h, 1<sup>re</sup> nuit
            si 24–48 h, total si &lt; 24 h.
            <strong>Stricte</strong>&nbsp;: 1<sup>re</sup> nuit toujours due,
            total si &lt; 48 h. Le réceptionniste peut surcharger le montant
            calculé (geste commercial).
          </p>
        </div>

        <div class="form-row">
          <label for="cutoff-hour" class="input-label">Heure de bascule comptable</label>
          <select
            id="cutoff-hour"
            v-model.number="financeDraft.businessDayCutoffHour"
            class="input"
          >
            <option v-for="h in 24" :key="h - 1" :value="h - 1">
              {{ String(h - 1).padStart(2, '0') }} h 00
            </option>
          </select>
          <p class="hint">
            L'heure à partir de laquelle un check-in / check-out est
            comptabilisé sur le jour suivant. Standard hôtelier&nbsp;: 5 h
            du matin. Un check-out à 02 h reste comptabilisé sur la veille.
          </p>
        </div>

        <div class="form-actions">
          <button
            class="btn btn-ghost"
            :disabled="!financeHasChanges || financeSaving"
            @click="resetFinanceDraft"
          >
            Annuler les modifications
          </button>
          <button
            class="btn btn-primary"
            :disabled="!financeHasChanges || financeSaving"
            @click="saveFinanceSettings"
          >
            <span v-if="financeSaving">Enregistrement…</span>
            <span v-else>Enregistrer</span>
          </button>
        </div>
      </div>

      <p v-else class="t-muted">Chargement…</p>
    </section>

    <!-- ── Modal type ── -->
    <div v-if="showTypeModal" class="modal-backdrop" @click.self="showTypeModal = false">
      <div class="modal">
        <header class="modal-header">
          <h2>{{ editingType ? 'Modifier' : 'Nouveau' }} type de chambre</h2>
          <button class="btn btn-ghost btn-sm" @click="showTypeModal = false">
            <i class="ti ti-x" aria-hidden="true"></i>
          </button>
        </header>
        <div class="modal-body">
          <div class="input-wrap">
            <label class="input-label">Nom</label>
            <input v-model="typeDraft.name" class="input" placeholder="Standard, Deluxe, Suite..." />
          </div>
          <div class="input-wrap">
            <label class="input-label">Description</label>
            <textarea v-model="typeDraft.description" class="input" rows="2"></textarea>
          </div>
          <div style="display:flex; gap:12px;">
            <div class="input-wrap" style="flex:1;">
              <label class="input-label">Tarif de base XOF</label>
              <input v-model="typeDraft.baseRateXof" class="input" type="number" step="0.01" />
            </div>
            <div class="input-wrap" style="flex:1;">
              <label class="input-label">Capacité (adultes)</label>
              <input v-model.number="typeDraft.maxOccupancy" class="input" type="number" min="1" max="20" />
            </div>
            <div class="input-wrap" style="width:110px;">
              <label class="input-label">Ordre</label>
              <input v-model.number="typeDraft.sortOrder" class="input" type="number" min="0" />
            </div>
          </div>
        </div>
        <footer class="modal-footer">
          <button class="btn btn-ghost" @click="showTypeModal = false">Annuler</button>
          <button class="btn btn-primary" @click="saveType">Enregistrer</button>
        </footer>
      </div>
    </div>

    <!-- ── Modal création unitaire ── -->
    <div v-if="showCreateRoomModal" class="modal-backdrop" @click.self="showCreateRoomModal = false">
      <div class="modal">
        <header class="modal-header">
          <h2>Nouvelle chambre</h2>
          <button class="btn btn-ghost btn-sm" @click="showCreateRoomModal = false">
            <i class="ti ti-x" aria-hidden="true"></i>
          </button>
        </header>
        <div class="modal-body">
          <div class="input-wrap">
            <label class="input-label">Numéro</label>
            <input v-model="newRoom.number" class="input" placeholder="101" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Type</label>
            <select v-model="newRoom.typeId" class="input">
              <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }} — {{ fmtXof(t.baseRateXof) }}</option>
            </select>
          </div>
          <div class="input-wrap">
            <label class="input-label">Étage</label>
            <select v-model="newRoom.floorId" class="input">
              <option value="">Aucun</option>
              <option v-for="f in floors" :key="f.id" :value="f.id">{{ f.number }} — {{ f.name || 'Étage ' + f.number }}</option>
            </select>
          </div>
          <div class="input-wrap">
            <label class="input-label">Notes (optionnel)</label>
            <textarea v-model="newRoom.notes" class="input" rows="2"></textarea>
          </div>
        </div>
        <footer class="modal-footer">
          <button class="btn btn-ghost" @click="showCreateRoomModal = false">Annuler</button>
          <button class="btn btn-primary" @click="createRoom">Créer</button>
        </footer>
      </div>
    </div>

    <!-- ── Modal édition chambre ── -->
    <div v-if="showEditRoomModal" class="modal-backdrop" @click.self="showEditRoomModal = false">
      <div class="modal">
        <header class="modal-header">
          <h2>Modifier la chambre {{ editingRoom?.number }}</h2>
          <button class="btn btn-ghost btn-sm" @click="showEditRoomModal = false">
            <i class="ti ti-x" aria-hidden="true"></i>
          </button>
        </header>
        <div class="modal-body">
          <div class="input-wrap">
            <label class="input-label">Numéro</label>
            <input v-model="editRoomDraft.number" class="input" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Type</label>
            <select v-model="editRoomDraft.typeId" class="input">
              <option v-for="t in types" :key="t.id" :value="t.id">
                {{ t.name }} — {{ fmtXof(t.baseRateXof) }}
              </option>
            </select>
          </div>
          <div class="input-wrap">
            <label class="input-label">Étage</label>
            <select v-model="editRoomDraft.floorId" class="input">
              <option value="">Aucun</option>
              <option v-for="f in floors" :key="f.id" :value="f.id">
                {{ f.number }} — {{ f.name || 'Étage ' + f.number }}
              </option>
            </select>
          </div>
          <div class="input-wrap">
            <label class="input-label">Notes (optionnel)</label>
            <textarea v-model="editRoomDraft.notes" class="input" rows="2"></textarea>
          </div>
        </div>
        <footer class="modal-footer">
          <button class="btn btn-ghost" @click="showEditRoomModal = false">Annuler</button>
          <button class="btn btn-primary" @click="saveRoom">Enregistrer</button>
        </footer>
      </div>
    </div>

    <!-- ── Modal création en lot ── -->
    <BulkCreateRoomsModal
      v-if="showBulkModal"
      :floors="floors"
      :types="types"
      :usage="usage"
      @close="showBulkModal = false"
      @created="handleBulkCreated"
    />
  </div>
</template>

<style scoped>
.config-view { padding: 24px; max-width: 1200px; margin: 0 auto; }
.config-header { margin-bottom: 20px; }
.config-header h1 { font-size: 24px; font-weight: 500; margin: 0 0 4px 0; }
.t-muted { color: var(--pms-ink-3); font-size: 13px; }

.tabs { display:flex; gap:4px; margin-bottom:18px; border-bottom:0.5px solid var(--pms-border); }
.tab {
  background:transparent; border:0; padding:10px 18px;
  font-size:13px; font-weight:500; color:var(--pms-ink-3);
  border-bottom:2px solid transparent; cursor:pointer;
}
.tab.active { color:var(--pms-ink); border-bottom-color: var(--pms-ink); }

.card { background:#fff; border:0.5px solid var(--pms-border); border-radius:16px; padding:20px 24px; }
.card h2 { font-size:16px; font-weight:500; margin:0 0 14px 0; }

.section-header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:16px; gap:12px; }
.section-header h2 { margin:0; }

.floor-create { display:flex; gap:8px; align-items:flex-start; }

.data-table { width:100%; border-collapse:collapse; font-size:13px; }
.data-table th { text-align:left; padding:10px 12px; color:var(--pms-ink-3); font-weight:500; border-bottom:0.5px solid var(--pms-border); }
.data-table td { padding:10px 12px; border-bottom:0.5px solid var(--pms-border); }
.row-muted td { opacity:0.6; }

.usage-pill { display:inline-flex; gap:6px; align-items:center; font-size:13px; }
.usage-pill strong { font-size:18px; font-weight:500; }

.filters { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
.filters .input { max-width:220px; }

.badge { display:inline-block; padding:2px 10px; border-radius:100px; font-size:11px; font-weight:500; background:var(--pms-sand-2); color:var(--pms-ink); }
.badge-success { background: var(--pms-green-light); color: var(--pms-green); }
.badge-muted   { background: var(--pms-sand-2); color: var(--pms-ink-3); }

.modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.4); display:flex; align-items:center; justify-content:center; z-index:50; }
.modal { background:#fff; border-radius:16px; width:520px; max-width:92vw; max-height:90vh; display:flex; flex-direction:column; }
.modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 22px; border-bottom:0.5px solid var(--pms-border); }
.modal-header h2 { font-size:15px; font-weight:500; margin:0; }
.modal-body { padding:20px 22px; overflow-y:auto; display:flex; flex-direction:column; gap:14px; }
.modal-footer { display:flex; justify-content:flex-end; gap:8px; padding:14px 22px; border-top:0.5px solid var(--pms-border); }

.input-wrap { display:flex; flex-direction:column; gap:6px; }
.input-label { font-size:11px; font-weight:500; color:var(--pms-ink-3); letter-spacing:0.04em; text-transform:uppercase; }
.input { height:38px; padding:0 14px; border:0.5px solid var(--pms-border-2); border-radius:10px; font-family:var(--font); font-size:13px; background:#fff; }
textarea.input { height:auto; padding:10px 14px; }

.btn { display:inline-flex; align-items:center; gap:6px; height:38px; padding:0 16px; border-radius:10px; border:none; font-family:var(--font); font-size:13px; font-weight:500; cursor:pointer; transition:all .15s; }
.btn-sm { height:30px; padding:0 12px; font-size:12px; }
.btn-primary  { background:var(--pms-ink); color:#fff; }
.btn-secondary{ background:#fff; color:var(--pms-ink); border:0.5px solid var(--pms-border-2); }
.btn-ghost    { background:transparent; color:var(--pms-ink-3); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* ── Onglet Finances (Sprint 14-A.2) ── */
.finance-form  { display:flex; flex-direction:column; gap:22px; max-width:640px; }
.form-row      { display:flex; flex-direction:column; gap:6px; }
.form-row .input-label { text-transform:none; font-size:13px; color:var(--pms-ink); font-weight:500; letter-spacing:0; }
.form-row .hint { font-size:12px; color:var(--pms-ink-3); margin:0; line-height:1.5; }
.form-actions  { display:flex; justify-content:flex-end; gap:12px; padding-top:8px; }

.error-box {
  display:flex; align-items:center; gap:10px;
  padding:12px 16px; border-radius:10px;
  background: var(--pms-red-light, #F5DADA);
  color: var(--pms-red, #B83232);
  border: 0.5px solid rgba(184, 50, 50, 0.2);
}
.error-box span { flex:1; }
</style>
