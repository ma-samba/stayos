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
const loading   = ref(false)
const error     = ref<string | null>(null)

async function submit(): Promise<void> {
  loading.value = true
  error.value   = null
  try {
    await staffService.createInvitation({
      email:     email.value.trim(),
      firstName: firstName.value.trim(),
      lastName:  lastName.value.trim(),
      role:      role.value,
    })
    emit('created')
    emit('close')
  } catch (e: unknown) {
    const resp = (e as { response?: { status?: number; data?: { error?: string } } }).response
    if (resp?.status === 409 || resp?.status === 422) {
      error.value = resp.data?.error ?? 'Action impossible.'
    } else {
      error.value = "Erreur lors de l'envoi de l'invitation."
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal">
      <div class="modal-header">
        <span class="modal-title">Inviter un employé</span>
        <button class="btn btn-ghost btn-icon-sm" @click="emit('close')">
          <i class="ti ti-x"></i>
        </button>
      </div>

      <p class="t-muted" style="margin-bottom:1rem;">
        L'invité recevra un email avec un lien d'activation valable 7 jours.
      </p>

      <form @submit.prevent="submit">
        <div class="input-wrap">
          <label class="input-label" for="inv-email">Email</label>
          <input
            id="inv-email"
            v-model="email"
            class="input"
            type="email"
            placeholder="employe@hotel.sn"
            required
          />
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <div class="input-wrap">
            <label class="input-label" for="inv-first">Prénom</label>
            <input id="inv-first" v-model="firstName" class="input" type="text" required />
          </div>
          <div class="input-wrap">
            <label class="input-label" for="inv-last">Nom</label>
            <input id="inv-last" v-model="lastName" class="input" type="text" required />
          </div>
        </div>

        <div class="input-wrap">
          <label class="input-label" for="inv-role">Rôle</label>
          <select id="inv-role" v-model="role" class="input">
            <option value="MANAGER">Manager</option>
            <option value="RECEPTIONIST">Réceptionniste</option>
            <option value="ACCOUNTANT">Comptable</option>
            <option value="HOUSEKEEPER">Femme/Valet de chambre</option>
          </select>
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
            <i class="ti ti-send"></i> Envoyer l'invitation
          </button>
        </div>
      </form>
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
  max-width: 460px;
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
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--pms-red-light);
  color: var(--pms-red);
  padding: 10px 12px;
  border-radius: var(--radius-md);
  font-size: 13px;
  margin: 0.5rem 0;
}

.modal-actions {
  display: flex; gap: 8px; justify-content: flex-end;
  margin-top: 1rem;
}

.t-muted { font-size: 13px; color: var(--pms-ink-3); }
</style>
