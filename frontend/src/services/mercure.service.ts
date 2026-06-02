// ──────────────────────────────────────────────────────────────
//  Service Mercure SSE — temps réel
//
//  Une EventSource = une connexion HTTP. Le navigateur limite HTTP/1.1
//  à 6 connexions par domaine : au-delà, les EventSource restent
//  bloquées en file d'attente indéfiniment et leurs topics ne
//  reçoivent JAMAIS d'event. Solution : multiplexer plusieurs topics
//  sur UNE seule EventSource via `subscribeMany`.
//
//  Limitation Mercure : le hub ne transmet PAS le topic d'origine
//  dans chaque message SSE (le champ `id:` est l'IRI auto-générée
//  de l'Update, pas le topic). L'appelant ne peut donc pas savoir
//  avec certitude quel topic a déclenché un message reçu — c'est à
//  lui d'identifier l'événement par fingerprint sur le payload.
//  Si plusieurs topics produisent des payloads identiques, les
//  regrouper sur des EventSources distinctes.
// ──────────────────────────────────────────────────────────────

type EventHandler<T = unknown> = (data: T) => void

class MercureService {
  private readonly hubUrl: string

  constructor() {
    this.hubUrl =
      import.meta.env.VITE_MERCURE_URL ?? '/.well-known/mercure'
  }

  /**
   * Abonnement mono-topic — délègue à `subscribeMany` pour rester
   * cohérent (1 EventSource par topic).
   */
  subscribe<T = unknown>(topic: string, handler: EventHandler<T>): () => void {
    return this.subscribeMany<T>([topic], handler)
  }

  /**
   * Abonnement multiplexé : ouvre UNE EventSource pour plusieurs
   * topics. Évite la saturation HTTP/1.1.
   *
   * Le `handler` reçoit le payload UNIQUEMENT. Pour distinguer les
   * topics d'origine, fingerprinter le payload (cf. notification-mapper)
   * ou regrouper les topics ambigus sur des subscriptions séparées.
   */
  subscribeMany<T = unknown>(
    topics: string[],
    handler: EventHandler<T>,
  ): () => void {
    if (topics.length === 0) return () => { /* noop */ }

    const url = new URL(this.hubUrl, window.location.origin)
    for (const topic of topics) {
      url.searchParams.append('topic', topic)
    }

    const eventSource = new EventSource(url.toString(), {
      withCredentials: false,
    })

    eventSource.onmessage = (event: MessageEvent) => {
      try {
        const data = JSON.parse(event.data) as T
        handler(data)
      } catch {
        // Messages mal formés ignorés
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
