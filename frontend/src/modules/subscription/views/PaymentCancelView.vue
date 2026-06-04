<script setup lang="ts">
import { useRouter } from 'vue-router'

const router = useRouter()

function goToInvoices(): void {
  router.push('/subscription/invoices')
}

function goToSubscription(): void {
  router.push('/subscription')
}
</script>

<template>
  <div class="payment-cancel">
    <div class="card payment-card">
      <div class="payment-icon">
        <i class="ti ti-info-circle" aria-hidden="true"></i>
      </div>

      <h1 class="payment-title">Paiement annulé</h1>

      <p class="payment-lead">
        Vous avez annulé le paiement. Aucune somme n'a été débitée.
        Vous pouvez réessayer à tout moment depuis l'historique de
        vos factures.
      </p>

      <div class="payment-actions">
        <button class="btn btn-primary" @click="goToInvoices">
          <i class="ti ti-file-invoice" aria-hidden="true"></i>
          Voir mes factures
        </button>
        <button class="btn btn-secondary" @click="goToSubscription">
          Retour à l'abonnement
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.payment-cancel {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: calc(100vh - 4rem);
  padding: 3rem 1.5rem;
}

.payment-card {
  max-width: 480px;
  width: 100%;
  padding: 2.5rem 2rem;
  text-align: center;
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
}

.payment-icon {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--pms-gold-light);
  color: var(--pms-gold-dark);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1.25rem;
}
.payment-icon i { font-size: 32px; }

.payment-title {
  font-size: 22px;
  font-weight: 500;
  color: var(--pms-ink);
  margin: 0 0 0.75rem;
}

.payment-lead {
  font-size: 14px;
  color: var(--pms-ink-2);
  line-height: 1.55;
  margin: 0 0 1.5rem;
}

.payment-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
}
</style>
