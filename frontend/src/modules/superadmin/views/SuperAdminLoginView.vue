<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useSuperAdminStore } from '@/stores/superadmin.store'
import { superadminService } from '@/services/superadmin.service'

const router = useRouter()
const route  = useRoute()
const store  = useSuperAdminStore()

const email    = ref('')
const password = ref('')
const loading  = ref(false)
const error    = ref<string | null>(null)

onMounted(() => {
  if (store.isAuthenticated) {
    router.push('/superadmin/metrics')
  }
})

async function submit(): Promise<void> {
  loading.value = true
  error.value   = null
  try {
    const { token } = await superadminService.login(email.value, password.value)
    store.setToken(token)
    if (!store.isSuperAdmin) {
      store.logout()
      error.value = "Ce compte n'a pas le rôle SuperAdmin."
      return
    }
    const redirect = route.query.redirect as string | undefined
    router.push(redirect ?? '/superadmin/metrics')
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
  <div class="sa-login-page">
    <div class="sa-login-card">
<div class="sa-login-logo">
        <i class="ti ti-shield-check" aria-hidden="true"></i>
        <span>StayOS</span>
      </div>
      <div class="sa-login-pill-wrap">
        <span class="sa-login-pill">Back-office plateforme</span>
      </div>
      <p class="sa-login-subtitle">Connexion réservée aux opérateurs StayOS</p>

      <div v-if="error" class="sa-login-error">
        <i class="ti ti-alert-circle" aria-hidden="true"></i>
        {{ error }}
      </div>

      <form @submit.prevent="submit">
        <div class="input-wrap">
          <label class="input-label" for="sa-email">Email</label>
          <div class="input-icon-wrap">
            <i class="ti ti-mail input-icon" aria-hidden="true"></i>
            <input
              id="sa-email"
              v-model="email"
              class="input input-with-icon"
              type="email"
              placeholder="admin@stayos.sn"
              required
              autocomplete="email"
            />
          </div>
        </div>

        <div class="input-wrap">
          <label class="input-label" for="sa-password">Mot de passe</label>
          <div class="input-icon-wrap">
            <i class="ti ti-lock input-icon" aria-hidden="true"></i>
            <input
              id="sa-password"
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
          class="btn btn-primary sa-login-btn"
          :disabled="loading || !email || !password"
        >
          <span v-if="loading" class="spinner-sm"></span>
          <span v-else>Accéder au back-office</span>
        </button>
      </form>
</div>
  </div>
</template>

<style scoped>
.sa-login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--pms-ink);
  padding: 1rem;
}

.sa-login-card {
  width: 100%;
  max-width: 380px;
  background: #fff;
  border-radius: var(--radius-xl);
  padding: 2.5rem 2rem;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
}

.sa-login-logo {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-bottom: 6px;
}
.sa-login-logo i {
  font-size: 24px;
  color: var(--pms-gold);
}
.sa-login-logo span {
  font-size: 20px;
  font-weight: 500;
  color: var(--pms-ink);
  letter-spacing: -0.02em;
}

.sa-login-pill-wrap {
  text-align: center;
  margin-bottom: 10px;
}
.sa-login-pill {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 100px;
  background: var(--pms-gold-light);
  color: var(--pms-gold-dark);
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.sa-login-subtitle {
  text-align: center;
  font-size: 13px;
  color: var(--pms-ink-3);
  margin-bottom: 1.75rem;
}

.sa-login-error {
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
.sa-login-error i { font-size: 16px; flex-shrink: 0; }

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
.input-icon-wrap { position: relative; }
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
.input.input-with-icon { padding-left: 38px; }

.sa-login-btn {
  width: 100%;
  height: 42px;
  margin-top: 0.5rem;
  justify-content: center;
  font-size: 14px;
}
.sa-login-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.spinner-sm {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
