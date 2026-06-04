<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatché quotidiennement (cron externe ou commande
 * stayos:subscriptions:check) — scanne tous les Subscription pour
 * envoyer les relances de fin d'essai, générer les factures de
 * renouvellement et suspendre les tenants en défaut de paiement.
 *
 * Pas de payload : le handler itère sur l'ensemble des subscriptions
 * actives ou trial.
 */
final class CheckSubscriptionsMessage
{
}
