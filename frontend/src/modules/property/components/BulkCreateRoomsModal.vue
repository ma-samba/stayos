<script setup lang="ts">
import { ref, computed } from 'vue'
import { useNotificationsStore } from '@/stores/notifications.store'
import { roomService } from '@/services/room.service'
import type { Floor, RoomType, RoomUsage } from '@/types/entities'

// ──────────────────────────────────────────────────────────────
//  BulkCreateRoomsModal — Sprint 13ter
//  Sélection étage + type + plage de numéros + préfixe optionnel.
//  Aperçu live de la liste. POST /api/rooms/bulk côté serveur.
// ──────────────────────────────────────────────────────────────

const props = defineProps<{
  floors: Floor[]
  types: RoomType[]
  usage: RoomUsage | null
}>()

const emit = defineEmits<{
  close: []
  created: []
}>()

const notif = useNotificationsStore()

const floorId     = ref(props.floors[0]?.id ?? '')
const typeId      = ref(props.types[0]?.id ?? '')
const startNumber = ref<number>(101)
const count       = ref<number>(5)
const prefix      = ref<string>('')
const creating    = ref(false)

const preview = computed<string[]>(() => {
  const c = Math.min(50, Math.max(1, count.value || 0))
  return Array.from({ length: c }, (_, i) => `${prefix.value}${startNumber.value + i}`)
})

const previewShort = computed(() => {
  const list = preview.value
  if (list.length <= 4) return list.join(', ')
  return `${list[0]}, ${list[1]}, …, ${list[list.length - 2]}, ${list[list.length - 1]}`
})

const remaining = computed(() => {
  if (!props.usage || props.usage.max === null) return null
  return props.usage.max - props.usage.used
})

const exceeds = computed(() => remaining.value !== null && count.value > remaining.value)

async function submit(): Promise<void> {
  if (!floorId.value || !typeId.value) {
    notif.pushUiToast('alert', 'Étage et type sont obligatoires.')
    return
  }
  if (count.value < 1 || count.value > 50) {
    notif.pushUiToast('alert', 'Le nombre doit être entre 1 et 50.')
    return
  }

  creating.value = true
  try {
    const rooms = await roomService.bulkCreate({
      floorId: floorId.value,
      typeId: typeId.value,
      startNumber: startNumber.value,
      count: count.value,
      prefix: prefix.value || null,
    })
    notif.pushUiToast('success', `${rooms.length} chambre(s) créée(s).`)
    emit('created')
  } catch (e: unknown) {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const msg = (e as any)?.response?.data?.error ?? 'Création en lot impossible.'
    notif.pushUiToast('alert', msg)
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal">
      <header class="modal-header">
        <h2>Création de chambres en lot</h2>
        <button class="btn btn-ghost btn-sm" @click="emit('close')">
          <i class="ti ti-x" aria-hidden="true"></i>
        </button>
      </header>
      <div class="modal-body">
        <div class="input-wrap">
          <label class="input-label">Étage</label>
          <select v-model="floorId" class="input">
            <option v-for="f in floors" :key="f.id" :value="f.id">
              {{ f.number }} — {{ f.name || 'Étage ' + f.number }}
            </option>
          </select>
        </div>
        <div class="input-wrap">
          <label class="input-label">Type de chambre</label>
          <select v-model="typeId" class="input">
            <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
        </div>

        <div style="display:flex; gap:12px;">
          <div class="input-wrap" style="flex:1;">
            <label class="input-label">Numéro de départ</label>
            <input v-model.number="startNumber" class="input" type="number" />
          </div>
          <div class="input-wrap" style="flex:1;">
            <label class="input-label">Nombre (max 50)</label>
            <input v-model.number="count" class="input" type="number" min="1" max="50" />
          </div>
          <div class="input-wrap" style="width:120px;">
            <label class="input-label">Préfixe</label>
            <input v-model="prefix" class="input" placeholder="B" maxlength="10" />
          </div>
        </div>

        <div class="preview">
          <span class="input-label" style="margin-bottom:6px; display:block;">Aperçu</span>
          <code>{{ previewShort }}</code>
        </div>

        <p v-if="exceeds" class="warning">
          ⚠️ Limite du plan dépassée.
          {{ remaining }} chambre(s) ajoutable(s) au plan
          {{ usage?.plan }} (utilisé&nbsp;: {{ usage?.used }} / {{ usage?.max }}).
        </p>
        <p v-else-if="remaining !== null" class="t-muted">
          {{ remaining }} chambre(s) ajoutable(s) avant la limite du plan {{ usage?.plan }}.
        </p>
      </div>
      <footer class="modal-footer">
        <button class="btn btn-ghost" @click="emit('close')">Annuler</button>
        <button class="btn btn-primary" :disabled="creating || exceeds" @click="submit">
          Créer {{ count }} chambre(s)
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.4); display:flex; align-items:center; justify-content:center; z-index:50; }
.modal { background:#fff; border-radius:16px; width:540px; max-width:92vw; max-height:90vh; display:flex; flex-direction:column; }
.modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 22px; border-bottom:0.5px solid var(--pms-border); }
.modal-header h2 { font-size:15px; font-weight:500; margin:0; }
.modal-body { padding:20px 22px; overflow-y:auto; display:flex; flex-direction:column; gap:14px; }
.modal-footer { display:flex; justify-content:flex-end; gap:8px; padding:14px 22px; border-top:0.5px solid var(--pms-border); }

.input-wrap { display:flex; flex-direction:column; gap:6px; }
.input-label { font-size:11px; font-weight:500; color:var(--pms-ink-3); letter-spacing:0.04em; text-transform:uppercase; }
.input { height:38px; padding:0 14px; border:0.5px solid var(--pms-border-2); border-radius:10px; font-family:var(--font); font-size:13px; background:#fff; }

.preview { background: var(--pms-sand); border-radius:10px; padding:12px 14px; }
.preview code { font-family: var(--mono, monospace); font-size:12px; color: var(--pms-ink); }
.warning { color: var(--pms-red, #b03030); font-size:12px; }
.t-muted { color: var(--pms-ink-3); font-size:12px; }

.btn { display:inline-flex; align-items:center; gap:6px; height:38px; padding:0 16px; border-radius:10px; border:none; font-family:var(--font); font-size:13px; font-weight:500; cursor:pointer; transition:all .15s; }
.btn:disabled { opacity:0.5; cursor:not-allowed; }
.btn-sm { height:30px; padding:0 12px; font-size:12px; }
.btn-primary  { background:var(--pms-ink); color:#fff; }
.btn-ghost    { background:transparent; color:var(--pms-ink-3); }
</style>
