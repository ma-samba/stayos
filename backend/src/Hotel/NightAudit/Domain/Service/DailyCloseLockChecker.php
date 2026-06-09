<?php

declare(strict_types=1);

namespace App\Hotel\NightAudit\Domain\Service;

use App\Hotel\NightAudit\Infrastructure\Repository\DailyCloseRepository;
use App\Shared\Exception\BusinessRuleException;

/**
 * Vérifie qu'une date donnée n'appartient pas à une journée close.
 *
 * Règle V1 : si la date <= business_date de la dernière clôture
 * effective (non rouverte), elle est verrouillée. Toute opération
 * corrective doit être faite via une nouvelle écriture datée du jour
 * courant.
 */
class DailyCloseLockChecker
{
    public function __construct(
        private readonly DailyCloseRepository $repository,
    ) {}

    /**
     * Date business de la dernière clôture EFFECTIVE (non rouverte).
     * Retourne null si aucune clôture n'a jamais été faite, ou si la
     * plus récente est rouverte sans clôture antérieure derrière elle.
     */
    public function getEffectiveLastClose(): ?\DateTimeImmutable
    {
        return $this->repository->findLatestEffective()?->getBusinessDate();
    }

    /**
     * Lève une BusinessRuleException si la date concernée tombe dans
     * une journée close. La date est comparée brute (00:00:00).
     */
    public function assertCanModifyDate(\DateTimeImmutable $date): void
    {
        $lastClose = $this->getEffectiveLastClose();
        if ($lastClose === null) {
            return;
        }

        $targetBd = $date->setTime(0, 0, 0);
        if ($targetBd <= $lastClose) {
            throw new BusinessRuleException(sprintf(
                "La journée du %s est clôturée. Faites une opération corrective datée d'aujourd'hui.",
                $targetBd->format('d/m/Y'),
            ));
        }
    }
}
