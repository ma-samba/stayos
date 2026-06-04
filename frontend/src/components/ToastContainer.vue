<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationsStore } from '@/stores/notifications.store'
import { routeFor, type NotificationSeverity } from '@/services/notification-mapper'

const store  = useNotificationsStore()
const router = useRouter()

const MAX_VISIBLE = 3
const DURATION_MS: Record<NotificationSeverity, number> = {
  info:    5000,
  success: 5000,
  warning: 8000,
  alert:   0, // infini — fermeture manuelle
}

const timers = new Map<string, ReturnType<typeof setTimeout>>()

// Sous-ensemble visible : les 3 toasts les plus récents
const visible = computed(() => store.toasts.slice(-MAX_VISIBLE))

const animating = ref<Set<string>>(new Set())

function scheduleDismissal(id: string, severity: NotificationSeverity): void {
  const ms = DURATION_MS[severity]
  if (ms <= 0) return
  if (timers.has(id)) return
  const handle = setTimeout(() => {
    dismiss(id)
  }, ms)
  timers.set(id, handle)
}

function clearTimer(id: string): void {
  const handle = timers.get(id)
  if (handle) {
    clearTimeout(handle)
    timers.delete(id)
  }
}

function dismiss(id: string): void {
  clearTimer(id)
  animating.value.add(id)
  // Laisser l'animation jouer puis retirer
  setTimeout(() => {
    store.dismissToast(id)
    animating.value.delete(id)
  }, 200)
}

watch(
  () => store.toasts.map(t => ({ id: t.id, severity: t.severity })),
  (next) => {
    for (const t of next) {
      scheduleDismissal(t.id, t.severity)
    }
  },
  { immediate: true, deep: true },
)

onBeforeUnmount(() => {
  for (const handle of timers.values()) clearTimeout(handle)
  timers.clear()
})

function severityIcon(severity: NotificationSeverity): string {
  switch (severity) {
    case 'success': return 'ti-circle-check'
    case 'warning': return 'ti-alert-triangle'
    case 'alert':   return 'ti-alert-circle'
    case 'info':
    default:        return 'ti-info-circle'
  }
}

function onToastClick(toast: typeof store.toasts[number]): void {
  // Les toasts UI (post-action) n'ont pas de route associée.
  if (toast.type === 'ui.feedback') {
    dismiss(toast.id)
    return
  }
  const path = routeFor({
    id: toast.id,
    type: toast.type,
    title: toast.title,
    body: toast.body,
    severity: toast.severity,
    metadata: toast.metadata,
    receivedAt: new Date().toISOString(),
  })
  dismiss(toast.id)
  if (path) router.push(path)
}
</script>

<template>
  <div class="toast-stack" aria-live="polite" aria-atomic="false">
    <transition-group name="toast" tag="div">
      <div
        v-for="t in visible"
        :key="t.id"
        :class="['toast', `sev-${t.severity}`, { 'is-dismissing': animating.has(t.id) }]"
        role="status"
        @click="onToastClick(t)"
      >
        <i :class="['ti', severityIcon(t.severity), 'toast-icon']" aria-hidden="true"></i>
        <div class="toast-body">
          <div class="toast-title">{{ t.title }}</div>
          <div v-if="t.body" class="toast-subtitle">{{ t.body }}</div>
        </div>
        <button
          class="toast-close"
          aria-label="Fermer"
          @click.stop="dismiss(t.id)"
        ><i class="ti ti-x" aria-hidden="true"></i></button>
      </div>
    </transition-group>
  </div>
</template>

<style scoped>
.toast-stack {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  gap: 10px;
  pointer-events: none;
  max-width: 380px;
}

.toast {
  pointer-events: auto;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-left-width: 3px;
  border-radius: var(--radius-md);
  box-shadow: 0 8px 24px rgba(26, 23, 20, 0.12);
  cursor: pointer;
  min-width: 280px;
  transition: transform 0.15s ease, opacity 0.2s ease;
}

.toast:hover { transform: translateX(-2px); }

.toast.sev-info    { border-left-color: var(--pms-blue); }
.toast.sev-success { border-left-color: var(--pms-green); }
.toast.sev-warning { border-left-color: var(--pms-gold-dark); }
.toast.sev-alert   { border-left-color: var(--pms-red); }

.toast-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
.toast.sev-info    .toast-icon { color: var(--pms-blue); }
.toast.sev-success .toast-icon { color: var(--pms-green); }
.toast.sev-warning .toast-icon { color: var(--pms-gold-dark); }
.toast.sev-alert   .toast-icon { color: var(--pms-red); }

.toast-body { flex: 1; min-width: 0; }
.toast-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--pms-ink);
  line-height: 1.35;
}
.toast-subtitle {
  font-size: 12px;
  color: var(--pms-ink-3);
  margin-top: 2px;
}

.toast-close {
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  border: none;
  background: transparent;
  color: var(--pms-ink-3);
  cursor: pointer;
  border-radius: var(--radius-sm);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.toast-close:hover { background: var(--pms-sand); color: var(--pms-ink); }
.toast-close i { font-size: 14px; }

/* ── Animations ── */
.toast-enter-from {
  opacity: 0;
  transform: translateX(20px);
}
.toast-enter-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.toast-leave-to {
  opacity: 0;
  transform: translateX(20px);
}
.toast-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
</style>
