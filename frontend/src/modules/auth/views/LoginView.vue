<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { authService } from '@/services/auth.service'

const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()

const email    = ref('')
const password = ref('')
const loading  = ref(false)
const error    = ref<string | null>(null)

onMounted(() => {
  if (auth.isAuthenticated) {
    router.push(auth.firstAccessiblePath())
  }
})

async function submit(): Promise<void> {
  loading.value = true
  error.value   = null

  try {
    const { token, refresh_token } = await authService.login(email.value, password.value)
    auth.setTokens(token, refresh_token)

    const redirect = route.query.redirect as string | undefined
    router.push(redirect ?? auth.firstAccessiblePath())
  } catch (e: unknown) {
    const status = (e as { response?: { status?: number } }).response?.status
    if (status === 429) {
      error.value = 'Trop de tentatives. Réessayez dans une minute.'
    } else if (status === 401) {
      error.value = 'Email ou mot de passe incorrect.'
    } else {
      error.value = 'Erreur de connexion. Réessayez.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
<!-- Logo -->
      <div class="login-logo">
        <i class="ti ti-building" aria-hidden="true"></i>
        <span>StayOS</span>
      </div>
      <p class="login-subtitle">Connectez-vous à votre espace</p>

      <!-- Error -->
      <div v-if="error" class="login-error">
        <i class="ti ti-alert-circle" aria-hidden="true"></i>
        {{ error }}
      </div>

      <!-- Form -->
      <form @submit.prevent="submit">
        <div class="input-wrap">
          <label class="input-label" for="login-email">Email</label>
          <div class="input-icon-wrap">
            <i class="ti ti-mail input-icon" aria-hidden="true"></i>
            <input
              id="login-email"
              v-model="email"
              class="input input-with-icon"
              type="email"
              placeholder="admin@hotel.sn"
              required
              autocomplete="email"
            />
          </div>
        </div>

        <div class="input-wrap">
          <label class="input-label" for="login-password">Mot de passe</label>
          <div class="input-icon-wrap">
            <i class="ti ti-lock input-icon" aria-hidden="true"></i>
            <input
              id="login-password"
              v-model="password"
              class="input input-with-icon"
              type="password"
              placeholder="••••••••••"
              required
              autocomplete="current-password"
            />
          </div>
        </div>

        <button
          type="submit"
          class="btn btn-primary login-btn"
          :disabled="loading || !email || !password"
        >
          <span v-if="loading" class="spinner-sm"></span>
          <span v-else>Se connecter</span>
        </button>
      </form>
</div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--pms-sand);
  padding: 1rem;
}

.login-card {
  width: 100%;
  max-width: 380px;
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-xl);
  padding: 2.5rem 2rem;
  box-shadow: 0 4px 24px rgba(26, 23, 20, 0.06);
}

/* ── Logo ── */
.login-logo {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-bottom: 6px;
}
.login-logo i {
  font-size: 22px;
  color: var(--pms-gold);
}
.login-logo span {
  font-size: 20px;
  font-weight: 500;
  color: var(--pms-ink);
  letter-spacing: -0.02em;
}

.login-subtitle {
  text-align: center;
  font-size: 13px;
  color: var(--pms-ink-3);
  margin-bottom: 1.75rem;
}

/* ── Error ── */
.login-error {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--pms-red-light);
  color: var(--pms-red);
  font-size: 13px;
  font-weight: 500;
  padding: 10px 14px;
  border-radius: var(--radius-md);
  margin-bottom: 1.25rem;
}
.login-error i {
  font-size: 16px;
  flex-shrink: 0;
}

/* ── Form ── */
.input-wrap {
  margin-bottom: 1rem;
}
.input-label {
  display: block;
  font-size: 11px;
  font-weight: 500;
  color: var(--pms-ink-3);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin-bottom: 6px;
}
.input-icon-wrap {
  position: relative;
}
.input-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 16px;
  color: var(--pms-ink-3);
  pointer-events: none;
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
  color: var(--pms-ink);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.input:focus {
  outline: none;
  border-color: var(--pms-ink);
  box-shadow: 0 0 0 3px rgba(26, 23, 20, 0.06);
}
.input.input-with-icon {
  padding-left: 38px;
}

/* ── Button ── */
.login-btn {
  width: 100%;
  height: 42px;
  margin-top: 0.5rem;
  justify-content: center;
  font-size: 14px;
}
.login-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* ── Spinner ── */
.spinner-sm {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
