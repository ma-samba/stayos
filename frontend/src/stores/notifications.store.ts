import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { mercureService } from '@/services/mercure.service'
import {
  mapEvent,
  fingerprintEvent,
  type Notification,
  type NotificationType,
} from '@/services/notification-mapper'
import { useAuthStore } from '@/stores/auth.store'

// ──────────────────────────────────────────────────────────────
//  Store Notifications — centre + toasts temps réel
//  Volatile par session (pas de persistance localStorage) :
//  notifications éphémères, c'est volontaire pour un PMS où la
//  session de travail est continue (et évite tout sujet RGPD).
// ──────────────────────────────────────────────────────────────

const MAX_NOTIFICATIONS = 50
const TOAST_BURST_WINDOW_MS = 2000
const TOAST_BURST_THRESHOLD = 3

// Audience par type d'event : à quels rôles une notif est-elle
// pertinente. Pure UX anti-bruit — le backend continue de broadcast
// à tout le tenant, ce n'est PAS de la sécurité. Aligné sur l'esprit
// de MODULE_ACCESS dans auth.store.ts :
// - HOUSEKEEPER → ses tâches + activité chambres, pas les flux
//   commerciaux/financiers.
// - RECEPTIONIST → tout l'opérationnel.
// - MANAGER → tout (figure dans chaque liste pour conserver la
//   vue d'ensemble et le bypass targetUserId).
// - ACCOUNTANT → événements financiers, pas le check-in/out ni
//   le ménage.
const NOTIFICATION_AUDIENCE: Record<NotificationType, string[]> = {
  'reservation.created':    ['MANAGER', 'RECEPTIONIST', 'ACCOUNTANT'],
  'reservation.checkin':    ['MANAGER', 'RECEPTIONIST'],
  'reservation.checkout':   ['MANAGER', 'RECEPTIONIST'],
  'reservation.cancelled':  ['MANAGER', 'RECEPTIONIST', 'ACCOUNTANT'],
  'payment.received':       ['MANAGER', 'RECEPTIONIST', 'ACCOUNTANT'],
  'room.status.changed':    ['MANAGER', 'RECEPTIONIST', 'HOUSEKEEPER'],
  'task.assigned':          ['MANAGER', 'RECEPTIONIST', 'HOUSEKEEPER'],
  'task.updated':           ['MANAGER', 'RECEPTIONIST', 'HOUSEKEEPER'],
  'alert.arrivals_today':   ['MANAGER', 'RECEPTIONIST'],
  'alert.late_checkout':    ['MANAGER', 'RECEPTIONIST'],
  'alert.unassigned_tasks': ['MANAGER', 'RECEPTIONIST'],
}

interface ToastEntry {
  id: string
  type: NotificationType
  severity: Notification['severity']
  title: string
  body?: string
  metadata?: Record<string, unknown>
  createdAt: number
}

export const useNotificationsStore = defineStore('notifications', () => {
  const notifications = ref<Notification[]>([])
  const toasts        = ref<ToastEntry[]>([])
  const isConnected   = ref(false)

  const unsubscribers: Array<() => void> = []

  // ── Computed ──────────────────────────────────────────────

  const unreadCount = computed(
    () => notifications.value.filter(n => !n.readAt).length,
  )

  // ── Anti-spam : grouper les rafales du même type ──────────

  function shouldGroupBurst(type: NotificationType): ToastEntry | null {
    const now = Date.now()
    const recent = toasts.value.filter(
      t => t.type === type && now - t.createdAt < TOAST_BURST_WINDOW_MS,
    )
    if (recent.length >= TOAST_BURST_THRESHOLD - 1) {
      // 2 toasts du même type < 2s + un nouveau → grouper
      return recent[0] ?? null
    }
    return null
  }

  function makeToastId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID()
    }
    return `t_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`
  }

  function pushToast(notification: Notification): void {
    const grouped = shouldGroupBurst(notification.type)
    if (grouped) {
      // Remplacer le toast existant par un toast groupé
      const sameType = toasts.value.filter(
        t => t.type === notification.type
          && Date.now() - t.createdAt < TOAST_BURST_WINDOW_MS,
      )
      const count = sameType.length + 1
      grouped.title = groupTitle(notification.type, count)
      grouped.body  = undefined
      // Retirer les autres
      toasts.value = toasts.value.filter(
        t => t.id === grouped.id || t.type !== notification.type,
      )
      return
    }

    toasts.value.push({
      id:        makeToastId(),
      type:      notification.type,
      severity:  notification.severity,
      title:     notification.title,
      body:      notification.body,
      metadata:  notification.metadata,
      createdAt: Date.now(),
    })
  }

  function dismissToast(id: string): void {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }

  // ── Actions principales ───────────────────────────────────

  function push(notification: Notification): void {
    notifications.value.unshift(notification)
    if (notifications.value.length > MAX_NOTIFICATIONS) {
      notifications.value.length = MAX_NOTIFICATIONS
    }
    pushToast(notification)
  }

  function markAsRead(id: string): void {
    const n = notifications.value.find(x => x.id === id)
    if (n && !n.readAt) n.readAt = new Date().toISOString()
  }

  function markAllAsRead(): void {
    const now = new Date().toISOString()
    for (const n of notifications.value) {
      if (!n.readAt) n.readAt = now
    }
  }

  function clear(): void {
    notifications.value = []
  }

  // ── Connexion Mercure ─────────────────────────────────────
  // 3 EventSources au total (vs 11 avant) :
  //   1) flux multiplexé pour 9 topics fingerprintables
  //   2) flux dédié pour reservation.checkin
  //   3) flux dédié pour reservation.checkout
  // Les payloads checkin/checkout étant identiques côté backend,
  // ils restent sur des connexions séparées pour préserver leur
  // distinction (cf. fingerprintEvent qui retourne null sur ce cas).

  const MULTIPLEXED_EVENTS: NotificationType[] = [
    'room.status.changed',
    'reservation.created',
    'reservation.cancelled',
    'task.assigned',
    'task.updated',
    'payment.received',
    'alert.arrivals_today',
    'alert.late_checkout',
    'alert.unassigned_tasks',
  ]

  function dispatch(
    eventType: NotificationType,
    data: Record<string, unknown>,
    userId: string | null,
    userRole: string | null,
    isManager: boolean,
  ): void {
    const notification = mapEvent(eventType, data)
    if (!notification) return

    // 1) Filtre par rôle (UX anti-bruit) : un rôle non concerné par
    //    ce type d'event ne reçoit rien. Le backend continue de
    //    broadcast — ce n'est pas une garantie de sécurité.
    const audience = NOTIFICATION_AUDIENCE[eventType]
    if (!userRole || !audience.includes(userRole)) {
      return
    }

    // 2) Filtre par destinataire pour les events ciblés (task.assigned) :
    //    manager bypass conservé pour la vue d'ensemble.
    if (
      notification.targetUserId
      && !isManager
      && notification.targetUserId !== userId
    ) {
      return
    }

    push(notification)
  }

  function connect(): void {
    if (isConnected.value) return

    const auth = useAuthStore()
    const tenantId = auth.tenantId
    if (!tenantId) return

    const userId  = auth.userId
    const userRole = auth.userRole
    const isManager = userRole === 'MANAGER'

    // (1) Flux multiplexé
    const multiplexedTopicUrls = MULTIPLEXED_EVENTS.map(
      e => mercureService.buildTopic(tenantId, e),
    )
    unsubscribers.push(
      mercureService.subscribeMany<Record<string, unknown>>(
        multiplexedTopicUrls,
        (data) => {
          const eventType = fingerprintEvent(data)
          if (!eventType) return
          dispatch(eventType, data, userId, userRole, isManager)
        },
      ),
    )

    // (2) Flux dédié checkin
    unsubscribers.push(
      mercureService.subscribe<Record<string, unknown>>(
        mercureService.buildTopic(tenantId, 'reservation.checkin'),
        (data) => dispatch('reservation.checkin', data, userId, userRole, isManager),
      ),
    )

    // (3) Flux dédié checkout
    unsubscribers.push(
      mercureService.subscribe<Record<string, unknown>>(
        mercureService.buildTopic(tenantId, 'reservation.checkout'),
        (data) => dispatch('reservation.checkout', data, userId, userRole, isManager),
      ),
    )

    isConnected.value = true
  }

  function disconnect(): void {
    while (unsubscribers.length > 0) {
      const fn = unsubscribers.pop()
      try { fn?.() } catch { /* noop */ }
    }
    isConnected.value = false
  }

  return {
    notifications,
    toasts,
    isConnected,
    unreadCount,
    push,
    markAsRead,
    markAllAsRead,
    clear,
    connect,
    disconnect,
    dismissToast,
  }
})

// ──────────────────────────────────────────────────────────────
//  Helpers (hors store pour réutilisation testable)
// ──────────────────────────────────────────────────────────────

function groupTitle(type: NotificationType, count: number): string {
  switch (type) {
    case 'task.assigned':    return `${count} nouvelles tâches assignées`
    case 'task.updated':     return `${count} mises à jour de tâches`
    case 'room.status.changed': return `${count} changements de chambres`
    case 'reservation.created': return `${count} nouvelles réservations`
    case 'payment.received': return `${count} paiements reçus`
    default:                 return `${count} nouveaux événements`
  }
}
