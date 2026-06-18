<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { guestService } from '@/services/guest.service'
import type { Guest } from '@/types/entities'

const router = useRouter()

const guests  = ref<Guest[]>([])
const loading = ref(true)
const error   = ref<string | null>(null)
const query   = ref('')
const page    = ref(1)

let debounce: ReturnType<typeof setTimeout> | null = null

async function loadGuests(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    if (query.value.trim().length >= 2) {
      guests.value = await guestService.search(query.value.trim())
    } else {
      guests.value = await guestService.list(page.value)
    }
  } catch {
    error.value = 'Erreur lors du chargement des clients'
  } finally {
    loading.value = false
  }
}

watch(query, () => {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(() => {
    page.value = 1
    loadGuests()
  }, 300)
})

function openProfile(id: string, edit = false): void {
  router.push({ path: `/guests/${id}`, query: edit ? { edit: '1' } : {} })
}

function guestInitials(firstName: string, lastName: string): string {
  return (firstName[0] + lastName[0]).toUpperCase()
}

// ── Création client ──

const showCreateForm = ref(false)
const creating       = ref(false)
const createError    = ref<string | null>(null)
const newGuest = ref({ firstName: '', lastName: '', email: '', phone: '' })

async function createGuest(): Promise<void> {
  if (!newGuest.value.firstName.trim() || !newGuest.value.lastName.trim()) {
    createError.value = 'Le prénom et le nom sont obligatoires.'
    return
  }
  creating.value = true
  createError.value = null
  try {
    const created = await guestService.create({
      firstName: newGuest.value.firstName.trim(),
      lastName:  newGuest.value.lastName.trim(),
      email:     newGuest.value.email.trim() || null,
      phone:     newGuest.value.phone.trim() || null,
    })
    showCreateForm.value = false
    newGuest.value = { firstName: '', lastName: '', email: '', phone: '' }
    router.push(`/guests/${created.id}`)
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'response' in e) {
      const err = e as { response: { data: { error?: string } } }
      createError.value = err.response?.data?.error ?? 'Erreur lors de la création'
    } else {
      createError.value = 'Erreur lors de la création'
    }
  } finally {
    creating.value = false
  }
}

onMounted(loadGuests)
</script>

<template>
  <div style="padding:1.5rem; max-width:1400px; margin:0 auto;">
<!-- En-tete -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
      <div>
        <h1 style="font-size:22px; font-weight:500; color:var(--pms-ink); margin-bottom:4px;">
          Clients
        </h1>
        <p class="t-muted">Fichier clients et historique des séjours</p>
      </div>
      <button class="btn btn-primary" @click="showCreateForm = true">
        <i class="ti ti-plus" aria-hidden="true"></i> Nouveau client
      </button>
    </div>

    <!-- Recherche -->
    <div style="margin-bottom:1.5rem; max-width:400px;">
      <div class="input-icon-wrap">
        <i class="ti ti-search input-icon" aria-hidden="true"></i>
        <input
          v-model="query"
          class="input"
          placeholder="Rechercher par nom, email, téléphone..."
          style="padding-left:36px;"
        />
      </div>
    </div>

    <!-- Chargement -->
    <div v-if="loading" style="display:flex; justify-content:center; padding:4rem 0;">
      <div class="spinner"></div>
    </div>

    <!-- Erreur -->
    <div v-else-if="error" class="empty-state">
      <i class="ti ti-alert-circle" aria-hidden="true"></i>
      <div>{{ error }}</div>
      <button class="btn btn-secondary btn-sm" @click="loadGuests()">Réessayer</button>
    </div>

    <!-- Vide -->
    <div v-else-if="guests.length === 0" class="empty-state">
      <i class="ti ti-users-minus" aria-hidden="true"></i>
      <div>{{ query.trim() ? 'Aucun client trouvé' : 'Aucun client enregistré' }}</div>
    </div>

    <!-- Tableau -->
    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Client</th>
            <th>Email</th>
            <th>Téléphone</th>
            <th>Séjours</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="g in guests"
            :key="g.id"
            style="cursor:pointer;"
            @click="openProfile(g.id)"
          >
            <td>
              <div style="display:flex; align-items:center; gap:8px;">
                <div class="avatar avatar-sm avatar-teal">
                  {{ guestInitials(g.firstName, g.lastName) }}
                </div>
                {{ g.firstName }} {{ g.lastName }}
              </div>
            </td>
            <td>{{ g.email || '—' }}</td>
            <td>{{ g.phone || '—' }}</td>
            <td>{{ g.totalStays ?? 0 }}</td>
            <td>
              <div style="display:flex; gap:4px;">
                <button class="btn btn-ghost btn-icon-sm" title="Voir la fiche" @click.stop="openProfile(g.id)">
                  <i class="ti ti-eye" aria-hidden="true"></i>
                </button>
                <button class="btn btn-ghost btn-icon-sm" title="Modifier" @click.stop="openProfile(g.id, true)">
                  <i class="ti ti-edit" aria-hidden="true"></i>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Modal Création client ── -->
    <div
      v-if="showCreateForm"
      style="position:fixed; inset:0; background:rgba(26,23,20,0.4); display:flex; align-items:center; justify-content:center; z-index:100;"
      @click.self="showCreateForm = false"
    >
      <div style="background:#fff; border-radius:var(--radius-xl); padding:1.5rem; width:440px; max-width:90vw;">
        <h3 style="font-size:16px; font-weight:500; color:var(--pms-ink); margin-bottom:1rem;">
          Nouveau client
        </h3>
        <div v-if="createError" style="background:var(--pms-red-light); color:var(--pms-red); padding:8px 12px; border-radius:var(--radius-md); font-size:12px; margin-bottom:12px;">
          {{ createError }}
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
          <div class="input-wrap">
            <label class="input-label">Prénom *</label>
            <input v-model="newGuest.firstName" class="input" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Nom *</label>
            <input v-model="newGuest.lastName" class="input" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Email</label>
            <input v-model="newGuest.email" class="input" type="email" />
          </div>
          <div class="input-wrap">
            <label class="input-label">Téléphone</label>
            <input v-model="newGuest.phone" class="input" type="tel" />
          </div>
        </div>
        <div style="display:flex; gap:8px; justify-content:flex-end;">
          <button class="btn btn-ghost btn-sm" @click="showCreateForm = false">Annuler</button>
          <button class="btn btn-primary btn-sm" :disabled="creating" @click="createGuest()">
            <span v-if="creating" class="spinner" style="width:12px; height:12px; border-width:1.5px;"></span>
            <template v-else><i class="ti ti-check" aria-hidden="true"></i> Créer</template>
          </button>
        </div>
      </div>
    </div>
</div>
</template>
