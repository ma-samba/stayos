<script setup lang="ts">
import { ref } from 'vue'
import { staffService } from '@/services/staff.service'
import type { StaffRole } from '@/types/staff'

const emit = defineEmits<{
  close: []
  created: []
}>()

const email     = ref('')
const firstName = ref('')
const lastName  = ref('')
const role      = ref<StaffRole>('RECEPTIONIST')
const phone     = ref('')
const loading   = ref(false)
const error     = ref<string | null>(null)
const result    = ref<{ tempPassword: string; email: string } | null>(null)
const copied    = ref(false)

async function submit(): Promise<void> {
  loading.value = true
  error.value   = null
  try {
    const created = await staffService.createStaff({
      email:     email.value.trim(),
      firstName: firstName.value.trim(),
      lastName:  lastName.value.trim(),
      role:      role.value,
      phone:     phone.value.trim() || null,
    })
    result.value = { tempPassword: created.tempPassword, email: created.email }
    emit('created')
  } catch (e: unknown) {
    const resp = (e as { response?: { status?: number; data?: { error?: string } } }).response
    if (resp?.status === 409 || resp?.status === 422) {
      error.value = resp.data?.error ?? 'Action impossible.'
    } else {
      error.value = 'Erreur lors de la création du compte.'
    }
  } finally {
    loading.value = false
  }
}

async function copyPassword(): Promise<void> {
  if (!result.value) return
  await navigator.clipboard.writeText(result.value.tempPassword)
  copied.value = true
  window.setTimeout(() => { copied.value = false }, 2000)
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal">
      <div class="modal-header">
        <span class="modal-title">
          {{ result ? 'Compte créé' : 'Créer un compte employé' }}
        </span>
        <button class="btn btn-ghost btn-icon-sm" @click="emit('close')">
          <i class="ti ti-x"></i>
        </button>
      </div>

      <!-- ── Étape 1 : formulaire ── -->
      <template v-if="!result">
        <p class="t-muted" style="margin-bottom:1rem;">
          Création directe avec un mot de passe temporaire. À utiliser
          si vous communiquez les identifiants en main propre.
        </p>

        <form @submit.prevent="submit">
          <div class="input-wrap">
            <label class="input-label" for="cs-email">Email</label>
            <input
              id="cs-email"
              v-model="email"
              class="input"
              type="email"
              placeholder="employe@hotel.sn"
              required
            />
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
            <div class="input-wrap">
              <label class="input-label" for="cs-first">Prénom</label>
              <input id="cs-first" v-model="firstName" class="input" type="text" required />
            </div>
            <div class="input-wrap">
              <label class="input-label" for="cs-last">Nom</label>
              <input id="cs-last" v-model="lastName" class="input" type="text" required />
            </div>
          </div>

          <div class="input-wrap">
            <label class="input-label" for="cs-role">Rôle</label>
            <select id="cs-role" v-model="role" class="input">
              <option value="MANAGER">Manager</option>
              <option value="RECEPTIONIST">Réceptionniste</option>
              <option value="ACCOUNTANT">Comptable</option>
              <option value="HOUSEKEEPER">Femme/Valet de chambre</option>
            </select>
          </div>

          <div class="input-wrap">
            <label class="input-label" for="cs-phone">Téléphone (optionnel)</label>
            <input id="cs-phone" v-model="phone" class="input" type="tel" placeholder="+221 ..." />
          </div>

          <div v-if="error" class="staff-error">
            <i class="ti ti-alert-circle"></i> {{ error }}
          </div>

          <div class="modal-actions">
            <button type="button" class="btn btn-ghost btn-sm" @click="emit('close')">Annuler</button>
            <button
              type="submit"
              class="btn btn-primary btn-sm"
              :disabled="loading || !email || !firstName || !lastName"
            >
              <i class="ti ti-user-plus"></i> Créer le compte
            </button>
          </div>
        </form>
      </template>

      <!-- ── Étape 2 : afficher le password temporaire ── -->
      <template v-else>
        <div class="staff-flash">
          <i class="ti ti-circle-check"></i>
          Compte créé pour <strong>{{ result.email }}</strong>.
        </div>

        <div class="warning-box">
          <i class="ti ti-alert-triangle"></i>
          <div>
            <strong>Mot de passe temporaire — affiché une seule fois.</strong>
            <p style="margin:4px 0 0; font-size:12px;">
              Communiquez-le immédiatement à l'employé. Il devra le
              changer après son premier login.
            </p>
          </div>
        </div>

        <div class="password-display">
          <code>{{ result.tempPassword }}</code>
          <button class="btn btn-secondary btn-sm" @click="copyPassword">
            <i :class="copied ? 'ti ti-check' : 'ti ti-copy'"></i>
            {{ copied ? 'Copié' : 'Copier' }}
          </button>
        </div>

        <div class="modal-actions">
          <button class="btn btn-primary btn-sm" @click="emit('close')">
            J'ai noté le mot de passe
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(26,23,20,0.4);
  display: flex; align-items: center; justify-content: center;
  z-index: 100;
}
.modal {
  background: #fff;
  border-radius: var(--radius-xl);
  padding: 1.5rem;
  width: 100%;
  max-width: 480px;
  box-shadow: 0 20px 48px rgba(0,0,0,0.2);
}
.modal-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 1rem;
}
.modal-title { font-size: 16px; font-weight: 500; color: var(--pms-ink); }

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

.staff-error {
  display: flex; align-items: center; gap: 8px;
  background: var(--pms-red-light);
  color: var(--pms-red);
  padding: 10px 12px;
  border-radius: var(--radius-md);
  font-size: 13px;
  margin: 0.5rem 0;
}

.staff-flash {
  display: flex; align-items: center; gap: 8px;
  background: var(--pms-green-light);
  color: var(--pms-green);
  padding: 10px 12px;
  border-radius: var(--radius-md);
  font-size: 13px;
  margin-bottom: 1rem;
}

.warning-box {
  display: flex; gap: 10px;
  background: var(--pms-gold-light);
  color: var(--pms-gold-dark);
  padding: 12px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
  margin-bottom: 1rem;
}
.warning-box i { font-size: 18px; flex-shrink: 0; margin-top: 2px; }

.password-display {
  display: flex; align-items: center; gap: 10px;
  background: var(--pms-sand-2);
  padding: 14px;
  border-radius: var(--radius-md);
  margin-bottom: 1rem;
}
.password-display code {
  flex: 1;
  font-family: var(--mono);
  font-size: 15px;
  font-weight: 500;
  color: var(--pms-ink);
  letter-spacing: 0.02em;
  word-break: break-all;
}

.modal-actions {
  display: flex; gap: 8px; justify-content: flex-end;
  margin-top: 1rem;
}

.t-muted { font-size: 13px; color: var(--pms-ink-3); }
</style>
