<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { superadminService } from '@/services/superadmin.service'
import type { CreateTenantResponse, SeedTemplate } from '@/types/superadmin'

const router = useRouter()

const hotelName = ref('')
const slug      = ref('')
const slugTouched = ref(false)
const managerEmail     = ref('')
const managerFirstName = ref('')
const managerLastName  = ref('')
const plan           = ref<'STARTER' | 'PRO' | 'ENTERPRISE'>('STARTER')
const initialStatus  = ref<'trial' | 'active'>('trial')
const seedTemplate   = ref<SeedTemplate>('empty')

const seedTemplateLabels: Record<SeedTemplate, string> = {
  empty:        'Vide — le manager configure tout',
  small_hotel:  'Petit hôtel (1 étage, 5 chambres)',
  medium_hotel: 'Hôtel moyen (2 étages, 12 chambres)',
}

const submitting = ref(false)
const error      = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})

const result   = ref<CreateTenantResponse | null>(null)
const copied   = ref(false)

// Normalisation live du slug : lowercase + remplacement accents/espaces
function normalizeSlug(input: string): string {
  return input
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40)
}

// Auto-pré-remplir le slug à partir du nom tant que l'utilisateur
// n'a pas touché le champ manuellement.
watch(hotelName, (value) => {
  if (!slugTouched.value) {
    slug.value = normalizeSlug(value)
  }
})

function onSlugInput(event: Event): void {
  slugTouched.value = true
  const target = event.target as HTMLInputElement
  slug.value = normalizeSlug(target.value)
}

const canSubmit = computed(() =>
  hotelName.value.trim() !== '' &&
  slug.value.length >= 2 &&
  managerEmail.value.trim() !== '' &&
  managerFirstName.value.trim() !== '' &&
  managerLastName.value.trim() !== '' &&
  !submitting.value,
)

async function submit(): Promise<void> {
  submitting.value = true
  error.value      = null
  fieldErrors.value = {}

  try {
    result.value = await superadminService.createTenant({
      hotel_name:         hotelName.value.trim(),
      slug:               slug.value,
      manager_email:      managerEmail.value.trim(),
      manager_first_name: managerFirstName.value.trim(),
      manager_last_name:  managerLastName.value.trim(),
      plan:               plan.value,
      initial_status:     initialStatus.value,
      seed_template:      seedTemplate.value,
    })
  } catch (e: unknown) {
    const resp = (e as { response?: { status?: number; data?: { error?: string; code?: string } } }).response
    if (resp?.status === 409) {
      fieldErrors.value.slug = resp.data?.error ?? 'Ce slug est déjà utilisé.'
    } else if (resp?.status === 422) {
      error.value = resp.data?.error ?? 'Données invalides.'
    } else {
      error.value = 'Erreur lors de la création du tenant.'
    }
  } finally {
    submitting.value = false
  }
}

async function copyPassword(): Promise<void> {
  if (!result.value) return
  await navigator.clipboard.writeText(result.value.manager_password)
  copied.value = true
  window.setTimeout(() => { copied.value = false }, 2000)
}

function resetForNewTenant(): void {
  hotelName.value         = ''
  slug.value              = ''
  slugTouched.value       = false
  managerEmail.value      = ''
  managerFirstName.value  = ''
  managerLastName.value   = ''
  plan.value              = 'STARTER'
  initialStatus.value     = 'trial'
  seedTemplate.value      = 'empty'
  result.value            = null
  error.value             = null
  fieldErrors.value       = {}
}

function gotoDetail(): void {
  if (!result.value) return
  router.push(`/superadmin/tenants/${result.value.tenant.slug}`)
}
</script>

<template>
  <div class="ct-page">
    <button class="ct-back" @click="router.push('/superadmin/tenants')">
      <i class="ti ti-arrow-left"></i> Retour à la liste
    </button>

    <h1>Nouveau tenant</h1>
    <p class="t-muted">Provisionnement direct par l'opérateur, sans OTP.</p>

    <!-- ── Résultat (modal-like inline) ────────────────────── -->
    <div v-if="result" class="ct-result-card">
      <div class="ct-success">
        <i class="ti ti-circle-check"></i>
        <div>
          <strong>Tenant créé.</strong>
          <p style="margin:4px 0 0;">
            <span class="t-mono">{{ result.tenant.slug }}</span>
            · {{ result.tenant.name }}
            · plan {{ result.tenant.planName }}
            · statut {{ result.tenant.status }}
          </p>
          <p
            v-if="result.seed_template && result.seed_template !== 'empty'"
            style="margin:6px 0 0; font-size:12px;"
          >
            Pré-rempli avec : <strong>{{ seedTemplateLabels[result.seed_template] }}</strong>
          </p>
        </div>
      </div>

      <div class="ct-warning">
        <i class="ti ti-alert-triangle"></i>
        <div>
          <strong>Mot de passe manager — affiché une seule fois.</strong>
          <p style="margin:4px 0 0; font-size:12px;">
            Communiquez-le immédiatement à <code class="t-mono">{{ managerEmail || result.tenant.slug }}</code>.
            Le manager devra le changer après son premier login.
          </p>
        </div>
      </div>

      <div class="ct-password">
        <code>{{ result.manager_password }}</code>
        <button class="btn btn-secondary btn-sm" @click="copyPassword">
          <i :class="copied ? 'ti ti-check' : 'ti ti-copy'"></i>
          {{ copied ? 'Copié' : 'Copier' }}
        </button>
      </div>

      <div class="ct-actions">
        <button class="btn btn-primary" @click="gotoDetail">
          Voir le tenant <i class="ti ti-arrow-right"></i>
        </button>
        <button class="btn btn-ghost" @click="resetForNewTenant">
          Créer un autre tenant
        </button>
      </div>
    </div>

    <!-- ── Formulaire ─────────────────────────────────────── -->
    <form v-else class="ct-form" @submit.prevent="submit">
      <div v-if="error" class="ct-error">
        <i class="ti ti-alert-circle"></i> {{ error }}
      </div>

      <section class="card">
        <h2 class="section-title">Informations hôtel</h2>

        <div class="input-wrap">
          <label class="input-label" for="ct-name">Nom de l'hôtel</label>
          <input
            id="ct-name"
            v-model="hotelName"
            class="input"
            type="text"
            placeholder="Hôtel Savana Dakar"
            required
          />
        </div>

        <div class="input-wrap">
          <label class="input-label" for="ct-slug">
            Slug (subdomain)
          </label>
          <input
            id="ct-slug"
            :value="slug"
            class="input t-mono"
            type="text"
            placeholder="hotel-savana"
            required
            @input="onSlugInput"
          />
          <div v-if="fieldErrors.slug" class="ct-field-error">
            {{ fieldErrors.slug }}
          </div>
          <div v-else class="ct-hint">
            URL finale : <span class="t-mono">https://{{ slug || '...' }}.getstayos.com</span>
          </div>
        </div>

        <div class="ct-row">
          <div class="input-wrap" style="flex:1;">
            <label class="input-label">Plan</label>
            <select v-model="plan" class="input">
              <option value="STARTER">Starter</option>
              <option value="PRO">Pro</option>
              <option value="ENTERPRISE">Enterprise</option>
            </select>
          </div>
          <div class="input-wrap" style="flex:1;">
            <label class="input-label">Statut initial</label>
            <div class="ct-radio-group">
              <label class="ct-radio">
                <input v-model="initialStatus" type="radio" value="trial" />
                Essai 14 jours
              </label>
              <label class="ct-radio">
                <input v-model="initialStatus" type="radio" value="active" />
                Actif immédiatement
              </label>
            </div>
          </div>
        </div>

        <div v-if="initialStatus === 'active'" class="ct-warning-inline">
          <i class="ti ti-alert-triangle"></i>
          Mode actif : aucune période d'essai. Utilisez ce mode uniquement
          pour les comptes vendus directement et déjà facturés.
        </div>
      </section>

      <section class="card" style="margin-top:1rem;">
        <h2 class="section-title">Manager principal</h2>

        <div class="input-wrap">
          <label class="input-label" for="ct-email">Email</label>
          <input
            id="ct-email"
            v-model="managerEmail"
            class="input"
            type="email"
            placeholder="manager@hotel.sn"
            required
          />
        </div>

        <div class="ct-row">
          <div class="input-wrap" style="flex:1;">
            <label class="input-label">Prénom</label>
            <input v-model="managerFirstName" class="input" type="text" required />
          </div>
          <div class="input-wrap" style="flex:1;">
            <label class="input-label">Nom</label>
            <input v-model="managerLastName" class="input" type="text" required />
          </div>
        </div>

        <div class="input-wrap" style="margin-top:0.85rem;">
          <label class="input-label" for="ct-seed">Pré-remplissage</label>
          <select id="ct-seed" v-model="seedTemplate" class="input">
            <option value="empty">{{ seedTemplateLabels.empty }}</option>
            <option value="small_hotel">{{ seedTemplateLabels.small_hotel }}</option>
            <option value="medium_hotel">{{ seedTemplateLabels.medium_hotel }}</option>
          </select>
          <div class="ct-hint">
            Le contenu pré-rempli est modifiable par le manager après création.
          </div>
        </div>
      </section>

      <div class="ct-submit">
        <button type="button" class="btn btn-ghost" @click="router.push('/superadmin/tenants')">
          Annuler
        </button>
        <button type="submit" class="btn btn-primary" :disabled="!canSubmit">
          <span v-if="submitting" class="spinner-sm"></span>
          <span v-else><i class="ti ti-building-plus"></i> Créer le tenant</span>
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.ct-page {
  padding: 1.5rem;
  max-width: 720px;
  margin: 0 auto;
}

.ct-back {
  display: inline-flex; align-items: center; gap: 4px;
  background: transparent; border: none;
  color: var(--pms-ink-3); font-family: var(--font); font-size: 13px;
  margin-bottom: 1rem;
  cursor: pointer; padding: 0;
}
.ct-back:hover { color: var(--pms-ink); }

h1 {
  font-size: 22px;
  font-weight: 500;
  color: var(--pms-ink);
  margin: 0 0 4px;
}
.t-muted { color: var(--pms-ink-3); font-size: 13px; margin-bottom: 1.5rem; }
.t-mono  { font-family: var(--mono); font-size: 12px; color: var(--pms-teal); }

.card {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  padding: 1.25rem 1.5rem;
}
.section-title {
  font-size: 13px; font-weight: 500;
  color: var(--pms-ink);
  text-transform: uppercase; letter-spacing: 0.04em;
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
.input.t-mono { font-family: var(--mono); }

.ct-row {
  display: flex; gap: 10px;
}

.ct-radio-group {
  display: flex; gap: 12px; padding-top: 6px;
}
.ct-radio {
  display: flex; align-items: center; gap: 6px;
  font-size: 13px; color: var(--pms-ink-2);
  cursor: pointer;
}

.ct-hint {
  font-size: 11px;
  color: var(--pms-ink-3);
  margin-top: 4px;
}

.ct-field-error {
  font-size: 12px;
  color: var(--pms-red);
  margin-top: 4px;
}

.ct-warning-inline {
  display: flex; align-items: flex-start; gap: 8px;
  margin-top: 8px;
  padding: 10px 12px;
  background: var(--pms-gold-light);
  color: var(--pms-gold-dark);
  border-radius: var(--radius-md);
  font-size: 12px;
}
.ct-warning-inline i { font-size: 16px; margin-top: 1px; }

.ct-error {
  display: flex; align-items: center; gap: 8px;
  background: var(--pms-red-light);
  color: var(--pms-red);
  padding: 10px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
  margin-bottom: 1rem;
}

.ct-submit {
  display: flex; gap: 8px; justify-content: flex-end;
  margin-top: 1rem;
}

/* ── Résultat ── */
.ct-result-card {
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  padding: 1.5rem;
}
.ct-success {
  display: flex; align-items: flex-start; gap: 10px;
  background: var(--pms-green-light);
  color: var(--pms-green);
  padding: 12px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
  margin-bottom: 1rem;
}
.ct-success i { font-size: 20px; flex-shrink: 0; }

.ct-warning {
  display: flex; align-items: flex-start; gap: 10px;
  background: var(--pms-gold-light);
  color: var(--pms-gold-dark);
  padding: 12px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
  margin-bottom: 0.75rem;
}
.ct-warning i { font-size: 20px; flex-shrink: 0; }

.ct-password {
  display: flex; align-items: center; gap: 10px;
  background: var(--pms-sand-2);
  padding: 12px;
  border-radius: var(--radius-md);
  margin-bottom: 1rem;
}
.ct-password code {
  flex: 1;
  font-family: var(--mono);
  font-size: 15px;
  font-weight: 500;
  word-break: break-all;
  color: var(--pms-ink);
}

.ct-actions {
  display: flex; gap: 8px; justify-content: flex-end;
}

.spinner-sm {
  width: 18px; height: 18px;
  border: 2px solid rgba(255,255,255,0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
