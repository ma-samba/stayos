// ──────────────────────────────────────────────────────────────
//  Notification mapper — transforme un événement Mercure
//  en Notification typée pour le store.
//
//  Les clés de payload sont alignées sur ce que les services
//  backend publient effectivement (vérifié sur :
//   - RoomService::changeStatus
//   - ReservationEngine::create/checkIn/checkOut/cancel
//   - HousekeepingService::assign/updateStatus
//   - InvoiceService::recordPayment + PaydunyaWebhookHandler
//   - OperationalAlertService).
// ──────────────────────────────────────────────────────────────

import { formatCurrency } from '@/utils/currency'

export type NotificationSeverity = 'info' | 'success' | 'warning' | 'alert'

export type NotificationType =
  | 'room.status.changed'
  | 'reservation.created'
  | 'reservation.checkin'
  | 'reservation.checkout'
  | 'reservation.cancelled'
  | 'task.assigned'
  | 'task.updated'
  | 'payment.received'
  | 'alert.arrivals_today'
  | 'alert.late_checkout'
  | 'alert.unassigned_tasks'

export interface Notification {
  id: string
  type: NotificationType
  title: string
  body?: string
  severity: NotificationSeverity
  targetUserId?: string | null
  metadata?: Record<string, unknown>
  receivedAt: string
  readAt?: string | null
}

export const NOTIFICATION_TOPICS: NotificationType[] = [
  'room.status.changed',
  'reservation.created',
  'reservation.checkin',
  'reservation.checkout',
  'reservation.cancelled',
  'task.assigned',
  'task.updated',
  'payment.received',
  'alert.arrivals_today',
  'alert.late_checkout',
  'alert.unassigned_tasks',
]

const ROOM_STATUS_FR: Record<string, string> = {
  available:    'disponible',
  occupied:     'occupée',
  cleaning:     'ménage en cours',
  maintenance:  'maintenance',
  out_of_order: 'hors service',
}

const TASK_STATUS_FR: Record<string, string> = {
  pending:     'en attente',
  in_progress: 'en cours',
  done:        'terminée',
  inspected:   'inspectée',
  skipped:     'ignorée',
}

const PAYMENT_METHOD_FR: Record<string, string> = {
  cash:          'espèces',
  wave:          'Wave',
  orange_money:  'Orange Money',
  card:          'carte bancaire',
  bank_transfer: 'virement',
  mobile_money:  'mobile money',
  ota:           'OTA',
}

function makeId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `n_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`
}

function base(
  type: NotificationType,
  severity: NotificationSeverity,
  title: string,
  metadata: Record<string, unknown>,
  extras: Partial<Notification> = {},
): Notification {
  return {
    id: makeId(),
    type,
    severity,
    title,
    metadata,
    receivedAt: new Date().toISOString(),
    readAt: null,
    ...extras,
  }
}

/**
 * Transforme un événement Mercure en Notification.
 * Retourne `null` si le payload est inexploitable (cas défensif).
 */
export function mapEvent(
  type: NotificationType,
  payload: Record<string, unknown>,
): Notification | null {
  if (!payload || typeof payload !== 'object') return null

  switch (type) {
    case 'room.status.changed': {
      const room   = String(payload.roomNumber ?? '?')
      const status = String(payload.status ?? '')
      const label  = ROOM_STATUS_FR[status] ?? status
      return base(type, 'info', `Chambre ${room} : ${label}`, payload)
    }

    case 'reservation.created': {
      const ref   = String(payload.confirmationNumber ?? '')
      const guest = String(payload.guest ?? 'client')
      return base(type, 'success', `Nouvelle réservation ${ref} — ${guest}`, payload)
    }

    case 'reservation.checkin': {
      // Payload ne contient PAS de guest → on identifie par chambre + ref
      const room = String(payload.room ?? '?')
      const ref  = String(payload.confirmationNumber ?? '')
      return base(type, 'success', `Check-in chambre ${room}`, payload, {
        body: ref ? `Réservation ${ref}` : undefined,
      })
    }

    case 'reservation.checkout': {
      const room = String(payload.room ?? '?')
      const ref  = String(payload.confirmationNumber ?? '')
      return base(type, 'info', `Check-out chambre ${room}`, payload, {
        body: ref ? `Réservation ${ref}` : undefined,
      })
    }

    case 'reservation.cancelled': {
      const ref    = String(payload.confirmationNumber ?? '')
      const reason = payload.reason ? ` (${String(payload.reason)})` : ''
      return base(type, 'warning', `Réservation annulée ${ref}${reason}`, payload)
    }

    case 'task.assigned': {
      const room  = String(payload.roomNumber ?? '?')
      const agent = payload.assignedToName ? ` → ${String(payload.assignedToName)}` : ''
      const targetUserId = payload.assignedToId ? String(payload.assignedToId) : null
      return base(type, 'info', `Tâche ménage chambre ${room}${agent}`, payload, {
        targetUserId,
      })
    }

    case 'task.updated': {
      const room   = String(payload.roomNumber ?? '?')
      const status = String(payload.status ?? '')
      const label  = TASK_STATUS_FR[status] ?? status
      return base(type, 'info', `Tâche ${room} : ${label}`, payload)
    }

    case 'payment.received': {
      const amount = formatCurrency(String(payload.amountXof ?? '0'))
      const method = PAYMENT_METHOD_FR[String(payload.method ?? '')] ?? String(payload.method ?? '')
      const guest  = payload.guestName ? ` — ${String(payload.guestName)}` : ''
      return base(type, 'success', `Paiement reçu ${amount} (${method})${guest}`, payload)
    }

    case 'alert.arrivals_today': {
      const count = Number(payload.count ?? 0)
      if (count <= 0) return null
      const label = count === 1 ? '1 arrivée prévue aujourd\'hui' : `${count} arrivées prévues aujourd'hui`
      return base(type, 'info', label, payload)
    }

    case 'alert.late_checkout': {
      const count = Number(payload.count ?? 0)
      if (count <= 0) return null
      const label = count === 1 ? '1 départ en retard' : `${count} départs en retard`
      return base(type, 'alert', label, payload)
    }

    case 'alert.unassigned_tasks': {
      const count = Number(payload.count ?? 0)
      if (count <= 0) return null
      const label = count === 1
        ? '1 tâche ménage sans agent'
        : `${count} tâches ménage sans agent`
      return base(type, 'warning', label, payload)
    }

    default:
      return null
  }
}

/**
 * Détermine le `NotificationType` depuis un payload reçu en multiplex
 * (où l'origine n'est pas connue — cf. mercure.service.ts).
 *
 * Limitation : `reservation.checkin` et `reservation.checkout` ont
 * exactement le même payload `{id, confirmationNumber, room}` — ils
 * ne sont PAS distinguables ici et doivent donc rester sur des
 * EventSources dédiées si le consommateur a besoin de les séparer.
 * Cette fonction retourne `null` pour les payloads ambigus.
 */
export function fingerprintEvent(
  payload: Record<string, unknown>,
): NotificationType | null {
  if (!payload || typeof payload !== 'object') return null
  const has = (k: string): boolean =>
    k in payload && payload[k] !== undefined && payload[k] !== null

  // Alertes opérationnelles
  if (has('count')) {
    if (has('arrivals'))  return 'alert.arrivals_today'
    if (has('checkouts')) return 'alert.late_checkout'
    if (has('tasks'))     return 'alert.unassigned_tasks'
  }

  // Tâches ménage
  if (has('taskId')) {
    if (has('assignedToId')) return 'task.assigned'
    return 'task.updated'
  }

  // Paiement
  if (has('invoiceId')) return 'payment.received'

  // Chambres
  if (has('roomId') && has('roomNumber') && has('status')) {
    return 'room.status.changed'
  }

  // Réservations
  if (has('id') && has('confirmationNumber')) {
    if (has('reason'))                              return 'reservation.cancelled'
    if (has('guest') || has('checkIn'))             return 'reservation.created'
    // checkin/checkout : payloads identiques, ambigus ici
    return null
  }

  return null
}

/**
 * Calcule la route cible pour un clic sur une notification.
 * Retourne `null` si pas de navigation pertinente.
 */
export function routeFor(notification: Notification): string | null {
  const m = notification.metadata ?? {}

  switch (notification.type) {
    case 'reservation.created':
    case 'reservation.checkin':
    case 'reservation.checkout':
    case 'reservation.cancelled':
      return m.id ? `/reservations/${String(m.id)}` : '/reservations'

    case 'task.assigned':
    case 'task.updated':
      return '/housekeeping'

    case 'payment.received':
      return m.invoiceId ? `/invoices/${String(m.invoiceId)}` : '/invoices'

    case 'room.status.changed':
      return '/rooms'

    case 'alert.arrivals_today':
    case 'alert.late_checkout':
    case 'alert.unassigned_tasks':
      return '/dashboard'

    default:
      return null
  }
}
