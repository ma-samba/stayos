// ──────────────────────────────────────────────────────────────
//  Service Mercure SSE — temps réel
// ──────────────────────────────────────────────────────────────

type EventHandler<T = unknown> = (data: T) => void

interface Subscription {
  topic: string
  eventSource: EventSource
  unsubscribe: () => void
}

class MercureService {
  private readonly hubUrl: string

  constructor() {
    this.hubUrl =
      import.meta.env.VITE_MERCURE_URL ?? '/.well-known/mercure'
  }

  /**
   * S'abonne à un topic Mercure.
   *
   * @param topic   Topic complet, ex: /hotel/{uuid}/room.status.changed
   * @param handler Callback appelé à chaque message
   * @returns       Fonction pour se désabonner
   */
  subscribe<T = unknown>(topic: string, handler: EventHandler<T>): () => void {
    const url = new URL(this.hubUrl, window.location.origin)
    url.searchParams.append('topic', topic)

    const eventSource = new EventSource(url.toString(), {
      withCredentials: false,
    })

    eventSource.onmessage = (event: MessageEvent) => {
      try {
        const data = JSON.parse(event.data) as T
        handler(data)
      } catch {
        // Ignorer les messages mal formés
      }
    }

    eventSource.onerror = () => {
      // Reconnexion automatique gérée par le navigateur
    }

    return () => eventSource.close()
  }

  /**
   * Construit le topic namespaced pour un tenant.
   *
   * @param tenantId UUID du tenant
   * @param event    Nom de l'événement, ex: 'room.status.changed'
   */
  buildTopic(tenantId: string, event: string): string {
    return `/hotel/${tenantId}/${event}`
  }
}

export const mercureService = new MercureService()
