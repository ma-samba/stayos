<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationsStore } from '@/stores/notifications.store'
import { routeFor, type Notification } from '@/services/notification-mapper'

const store  = useNotificationsStore()
const router = useRouter()

const open = ref(false)
const rootEl = ref<HTMLElement | null>(null)

const recent = computed<Notification[]>(() => store.notifications.slice(0, 20))

function toggle(): void {
  open.value = !open.value
}

function close(): void {
  open.value = false
}

function handleClickOutside(e: MouseEvent): void {
  if (!open.value) return
  if (rootEl.value && !rootEl.value.contains(e.target as Node)) {
    close()
  }
}

onMounted(() => document.addEventListener('mousedown', handleClickOutside))
onBeforeUnmount(() => document.removeEventListener('mousedown', handleClickOutside))

function onClickItem(n: Notification): void {
  store.markAsRead(n.id)
  const path = routeFor(n)
  close()
  if (path) router.push(path)
}

function onMarkAll(): void {
  store.markAllAsRead()
}

function onClear(): void {
  store.clear()
}

// ── Helpers d'affichage ─────────────────────────────────────

function severityIcon(severity: Notification['severity']): string {
  switch (severity) {
    case 'success': return 'ti-circle-check'
    case 'warning': return 'ti-alert-triangle'
    case 'alert':   return 'ti-alert-circle'
    case 'info':
    default:        return 'ti-info-circle'
  }
}

function relativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime()
  const min = Math.round(diff / 60000)
  if (min < 1)  return 'à l\'instant'
  if (min < 60) return `il y a ${min} min`
  const h = Math.round(min / 60)
  if (h < 24)   return `il y a ${h} h`
  const d = Math.round(h / 24)
  return `il y a ${d} j`
}
</script>

<template>
  <div ref="rootEl" class="notif-root">
    <button
      class="notif-bell"
      :aria-label="`Notifications${store.unreadCount > 0 ? ` (${store.unreadCount} non lues)` : ''}`"
      :aria-expanded="open"
      @click="toggle"
    >
      <i class="ti ti-bell" aria-hidden="true"></i>
      <span
        v-if="store.unreadCount > 0"
        class="notif-badge"
        aria-hidden="true"
      >{{ store.unreadCount > 9 ? '9+' : store.unreadCount }}</span>
      <span
        v-if="!store.isConnected"
        class="notif-offline-dot"
        aria-label="Connexion temps réel indisponible"
        title="Connexion temps réel indisponible"
      ></span>
    </button>

    <div v-if="open" class="notif-popover" role="dialog" aria-label="Notifications">
      <header class="notif-header">
        <span class="notif-title">Notifications</span>
        <button
          v-if="store.unreadCount > 0"
          class="notif-link"
          @click="onMarkAll"
        >
Tout marquer comme lu
</button>
      </header>

      <div v-if="recent.length === 0" class="notif-empty">
        <i class="ti ti-bell-off" aria-hidden="true"></i>
        <span>Aucune notification</span>
      </div>

      <ul v-else class="notif-list">
        <li
          v-for="n in recent"
          :key="n.id"
          :class="['notif-item', `sev-${n.severity}`, { 'is-unread': !n.readAt }]"
          @click="onClickItem(n)"
        >
          <i :class="['ti', severityIcon(n.severity), 'notif-icon']" aria-hidden="true"></i>
          <div class="notif-content">
            <div class="notif-item-title">{{ n.title }}</div>
            <div v-if="n.body" class="notif-item-body">{{ n.body }}</div>
            <div class="notif-time">{{ relativeTime(n.receivedAt) }}</div>
          </div>
        </li>
      </ul>

      <footer v-if="recent.length > 0" class="notif-footer">
        <button class="notif-link" @click="onClear">Effacer</button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.notif-root { position: relative; }

/* ── Bell ── */
.notif-bell {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-md);
  border: none;
  background: rgba(255, 255, 255, 0.06);
  color: rgba(255, 255, 255, 0.85);
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease;
}
.notif-bell:hover {
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
}
.notif-bell i { font-size: 18px; }

.notif-badge {
  position: absolute;
  top: -3px;
  right: -3px;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  border-radius: 100px;
  background: var(--pms-red);
  color: #fff;
  font-size: 10px;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1.5px solid var(--pms-ink);
}

.notif-offline-dot {
  position: absolute;
  bottom: 2px;
  right: 2px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--pms-ink-3);
}

/* ── Popover ──
   `position: fixed` au lieu de `absolute` parce que la sidebar parente a
   `overflow: hidden` (nécessaire pour l'animation de réduction). En absolute,
   le popover serait clippé. En fixed, il s'ancre au viewport et reste visible
   quel que soit l'état de la sidebar (pleine ou réduite à 64px). */
.notif-popover {
  position: fixed;
  bottom: 72px;
  left: 12px;
  width: 360px;
  max-height: 480px;
  display: flex;
  flex-direction: column;
  background: #fff;
  border: 0.5px solid var(--pms-border);
  border-radius: var(--radius-lg);
  box-shadow: 0 12px 32px rgba(26, 23, 20, 0.12);
  z-index: 100;
  overflow: hidden;
}

.notif-header,
.notif-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 0.5px solid var(--pms-border);
}
.notif-footer {
  border-top: 0.5px solid var(--pms-border);
  border-bottom: none;
  justify-content: flex-end;
}
.notif-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--pms-ink);
}
.notif-link {
  border: none;
  background: transparent;
  color: var(--pms-teal);
  font-family: var(--font);
  font-size: 12px;
  cursor: pointer;
  padding: 0;
}
.notif-link:hover { text-decoration: underline; }

/* ── Empty ── */
.notif-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 36px 16px;
  color: var(--pms-ink-3);
  font-size: 13px;
}
.notif-empty i { font-size: 28px; color: var(--pms-ink-3); }

/* ── Liste ── */
.notif-list {
  flex: 1;
  list-style: none;
  margin: 0;
  padding: 0;
  overflow-y: auto;
}
.notif-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 16px;
  cursor: pointer;
  transition: background 0.12s ease;
  border-bottom: 0.5px solid var(--pms-border);
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover      { background: #faf9f7; }
.notif-item.is-unread  { background: rgba(29, 110, 110, 0.04); }

.notif-icon {
  font-size: 16px;
  margin-top: 1px;
  flex-shrink: 0;
}
.notif-item.sev-info    .notif-icon { color: var(--pms-blue); }
.notif-item.sev-success .notif-icon { color: var(--pms-green); }
.notif-item.sev-warning .notif-icon { color: var(--pms-gold-dark); }
.notif-item.sev-alert   .notif-icon { color: var(--pms-red); }

.notif-content { flex: 1; min-width: 0; }
.notif-item-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--pms-ink);
  line-height: 1.35;
  word-wrap: break-word;
}
.notif-item-body {
  font-size: 12px;
  color: var(--pms-ink-3);
  margin-top: 2px;
}
.notif-time {
  font-size: 11px;
  color: var(--pms-ink-3);
  margin-top: 4px;
}
</style>
