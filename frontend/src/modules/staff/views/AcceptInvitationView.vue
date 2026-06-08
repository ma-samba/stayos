<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { staffService } from '@/services/staff.service'
import type { PublicInvitationInfo } from '@/types/staff'

const route  = useRoute()
const router = useRouter()

const token   = route.params.token as string
const info    = ref<PublicInvitationInfo | null>(null)
const loading = ref(true)
const error   = ref<string | null>(null)

const password        = ref('')
const passwordConfirm = ref('')
const submitting      = ref(false)
const submitError     = ref<string | null>(null)

const ROLE_LABEL: Record<string, string> = {
  MANAGER:       'Manager',
  RECEPTIONIST:  'Réceptionniste',
  ACCOUNTANT:    'Comptable',
  HOUSEKEEPER:   'Femme/Valet de chambre',
}

const canSubmit = computed(
  () =>
    password.value.length >= 8 &&
    password.value === passwordConfirm.value &&
    !submitting.value,
)

const passwordMatchError = computed(
  () =>
    passwordConfirm.value !== '' &&
    password.value !== passwordConfirm.value
      ? 'Les deux mots de passe ne correspondent pas.'
      : null,
)

async function load(): Promise<void> {
  loading.value = true
  error.value   = null
  try {
    info.value = await staffService.getInvitationByToken(token)
  } catch (e: unknown) {
    const resp = (e as { response?: { status?: number; data?: { error?: string } } }).response
    error.value = resp?.data?.error ?? 'Lien invalide ou expiré.'
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  submitting.value  = true
  submitError.value = null
  try {
    const result = await staffService.acceptInvitation(token, password.value)
    router.push({
      path: '/login',
      query: { email: result.email, message: 'invitation_accepted' },
    })
  } catch (e: unknown) {
    const resp = (e as { response?: { status?: number; data?: { error?: string } } }).response
    submitError.value = resp?.data?.error ?? "Erreur lors de l'activation."
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="accept-page">
    <div class="accept-card">
      <div class="accept-logo">
        <i class="ti ti-building"></i>
        <span>StayOS</span>
      </div>

      <div v-if="loading" class="accept-loading">Chargement…</div>

      <div v-else-if="error" class="accept-error">
        <i class="ti ti-alert-circle"></i>
        <div>
          <strong>Lien invalide ou expiré</strong>
          <p style="margin:4px 0 0; font-size:13px;">
            {{ error }} Demandez à votre manager de vous envoyer une
            nouvelle invitation.
          </p>
        </div>
      </div>

      <template v-else-if="info">
        <p class="accept-greeting">
          Bonjour <strong>{{ info.firstName }}</strong>,
        </p>
        <h1>Activez votre compte StayOS</h1>
        <p class="accept-desc">
          Vous êtes invité(e) à rejoindre l'équipe en tant que
          <strong>{{ ROLE_LABEL[info.role] ?? info.role }}</strong>.
          Choisissez un mot de passe pour activer votre compte.
        </p>

        <form @submit.prevent="submit">
          <div class="input-wrap">
            <label class="input-label" for="acc-email">Email</label>
            <input id="acc-email" class="input" :value="info.email" disabled />
          </div>

          <div class="input-wrap">
            <label class="input-label" for="acc-pwd">Mot de passe (8 caractères minimum)</label>
            <input
              id="acc-pwd"
              v-model="password"
              class="input"
              type="password"
              minlength="8"
              required
              autocomplete="new-password"
            />
          </div>

          <div class="input-wrap">
            <label class="input-label" for="acc-pwd2">Confirmer</label>
            <input
              id="acc-pwd2"
              v-model="passwordConfirm"
              class="input"
              type="password"
              minlength="8"
              required
              autocomplete="new-password"
            />
            <div v-if="passwordMatchError" class="accept-warn">{{ passwordMatchError }}</div>
          </div>

          <div v-if="submitError" class="accept-error" style="margin-bottom:0.75rem;">
            <i class="ti ti-alert-circle"></i> {{ submitError }}
          </div>

          <button
            type="submit"
            class="btn btn-primary accept-btn"
            :disabled="!canSubmit"
          >
            <span v-if="submitting" class="spinner-sm"></span>
            <span v-else>Activer mon compte</span>
          </button>
        </form>
      </template>
    </div>
  </div>
</template>

<style scoped>
.accept-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--pms-sand);
  padding: 1rem;
}

.accept-card {
  width: 100%;
  max-width: 420px;
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-xl);
  padding: 2.5rem 2rem;
  box-shadow: 0 4px 24px rgba(26, 23, 20, 0.06);
}

.accept-logo {
  display: flex; align-items: center; justify-content: center;
  gap: 8px;
  margin-bottom: 2rem;
}
.accept-logo i { font-size: 22px; color: var(--pms-gold); }
.accept-logo span {
  font-size: 20px;
  font-weight: 500;
  color: var(--pms-ink);
  letter-spacing: -0.02em;
}

.accept-greeting {
  font-size: 14px;
  color: var(--pms-ink-2);
  margin: 0 0 4px;
}
h1 {
  font-size: 20px;
  font-weight: 500;
  color: var(--pms-ink);
  margin: 0 0 12px;
}
.accept-desc {
  font-size: 13px;
  color: var(--pms-ink-3);
  margin-bottom: 1.5rem;
  line-height: 1.5;
}

.accept-loading {
  padding: 2rem 0;
  text-align: center;
  color: var(--pms-ink-3);
}

.accept-error {
  display: flex; gap: 10px;
  background: var(--pms-red-light);
  color: var(--pms-red);
  padding: 12px 14px;
  border-radius: var(--radius-md);
  font-size: 13px;
}
.accept-error i { font-size: 18px; flex-shrink: 0; margin-top: 2px; }

.accept-warn {
  font-size: 12px;
  color: var(--pms-red);
  margin-top: 4px;
}

.input-wrap { margin-bottom: 1rem; }
.input-label {
  display: block;
  font-size: 11px;
  font-weight: 500;
  color: var(--pms-ink-3);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin-bottom: 6px;
}
.input {
  width: 100%;
  height: 42px;
  padding: 0 14px;
  border: 0.5px solid var(--pms-border-2);
  border-radius: var(--radius-md);
  font-family: var(--font);
  font-size: 13px;
  background: #fff;
}
.input:focus {
  outline: none;
  border-color: var(--pms-ink);
  box-shadow: 0 0 0 3px rgba(26,23,20,0.06);
}
.input:disabled {
  background: var(--pms-sand-2);
  color: var(--pms-ink-3);
}

.accept-btn {
  width: 100%;
  height: 42px;
  margin-top: 0.5rem;
  justify-content: center;
  font-size: 14px;
}
.accept-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.spinner-sm {
  width: 18px; height: 18px;
  border: 2px solid rgba(255,255,255,0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
