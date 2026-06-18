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
//
//  Sprint 14-B.2.1 — Préparation prod : le cookie httpOnly
//  `mercureAuthorization` est posé par GET /api/mercure/token. Le
//  EventSource est ouvert avec `withCredentials: true` pour que le
//  browser envoie ce cookie au hub. En dev le hub reste anonymous
//  (le cookie n'est pas vérifié), mais en prod (Sprint 14-C) le hub
//  passera en anonymous=false et le cookie sera la seule autorisation
//  acceptée.
// ──────────────────────────────────────────────────────────────

import api from './api.service'

type EventHandler<T = unknown> = (data: T) => void

interface MercureTokenResponse {
  data: {
    token: string
    ttlSeconds: number
  }
}

class MercureService {
  private readonly hubUrl: string
  private tokenPromise: Promise<void> | null = null
  private tokenExpiresAt = 0
  private refreshTimer: ReturnType<typeof setTimeout> | null = null

  constructor() {
    this.hubUrl =
      import.meta.env.VITE_MERCURE_URL ?? '/.well-known/mercure'
  }

  /**
   * Garantit qu'un cookie Mercure subscriber est posé avant d'établir
   * une connexion EventSource.
   *
   * Pattern :
   * - 1er appel : récupère le token (cookie posé par le backend)
   * - Appels suivants tant que le token est valide : noop
   * - Avant expiration (-10 min) : refresh anticipé via setTimeout
   *
   * En cas d'échec, on logge mais on ne bloque pas la connexion : en
   * dev le hub est anonymous donc ça marchera quand même, et en prod
   * la reconnexion automatique d'EventSource réessaiera après un
   * nouveau token.
   */
  private async ensureToken(): Promise<void> {
    const now = Date.now()
    if (now < this.tokenExpiresAt - 10 * 60 * 1000) {
      return
    }

    if (this.tokenPromise) {
      return this.tokenPromise
    }

    this.tokenPromise = (async () => {
      try {
        const resp = await api.get<MercureTokenResponse>(
          '/mercure/token',
          { withCredentials: true },
        )
        const ttlMs = resp.data.data.ttlSeconds * 1000
        this.tokenExpiresAt = Date.now() + ttlMs

        if (this.refreshTimer) clearTimeout(this.refreshTimer)
        this.refreshTimer = setTimeout(() => {
          void this.ensureToken()
        }, ttlMs - 10 * 60 * 1000)
      } catch (e) {
        console.warn('Mercure token fetch failed', e)
      } finally {
        this.tokenPromise = null
      }
    })()

    return this.tokenPromise
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

    // Lazy : on déclenche la récupération du cookie en parallèle. La
    // 1ère EventSource peut partir sans cookie ; le browser réémettra
    // automatiquement après la première reconnexion (qui aura lieu
    // dès que le cookie sera posé).
    void this.ensureToken()

    const url = new URL(this.hubUrl, window.location.origin)
    for (const topic of topics) {
      url.searchParams.append('topic', topic)
    }

    const eventSource = new EventSource(url.toString(), {
      withCredentials: true,
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

  /**
   * Réinitialise l'état (appelé au logout pour clear le timer).
   */
  reset(): void {
    if (this.refreshTimer) {
      clearTimeout(this.refreshTimer)
      this.refreshTimer = null
    }
    this.tokenExpiresAt = 0
    this.tokenPromise = null
  }
}

export const mercureService = new MercureService()
