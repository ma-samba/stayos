<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Déclenche la publication des alertes opérationnelles quotidiennes
 * (arrivées, départs en retard, tâches non assignées) pour tous les tenants actifs.
 *
 * Dispatché par la commande console stayos:alerts:daily.
 * Le scheduler Messenger (Sprint 12) pourra déclencher ce message automatiquement.
 */
final class PublishDailyAlertsMessage
{
    public function __construct(
        public readonly ?string $tenantSlug = null,
        public readonly bool    $includeLateCheckouts = false,
    ) {}
}
