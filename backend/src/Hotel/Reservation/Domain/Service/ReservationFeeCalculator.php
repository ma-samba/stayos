<?php

declare(strict_types=1);

namespace App\Hotel\Reservation\Domain\Service;

use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Domain\Enum\CancellationPolicy;
use App\Hotel\Reservation\Domain\Enum\NoShowPolicy;

/**
 * Service pur de calcul des frais no-show / annulation.
 *
 * Sans dépendance externe → testable unitairement sans mocks BDD.
 * Les montants restent strings bcmath pour la précision XOF.
 *
 * Convention :
 *   - rateXof  = tarif d'une nuit
 *   - totalXof = total séjour (rateXof × nights)
 */
class ReservationFeeCalculator
{
    /**
     * Calcule le montant à facturer en cas de no-show selon la politique.
     */
    public function computeNoShowFee(Reservation $reservation, NoShowPolicy $policy): string
    {
        return match ($policy) {
            NoShowPolicy::NONE        => '0.00',
            NoShowPolicy::FIRST_NIGHT => $this->normalize($reservation->getRateXof()),
            NoShowPolicy::FULL        => $this->normalize($reservation->getTotalXof()),
        };
    }

    /**
     * Calcule les frais d'annulation selon la politique tenant et le
     * délai entre $now et le check-in.
     *
     * $now est injecté pour la testabilité.
     *
     * @return array{amountXof: string, reason: string, hoursBefore: int}
     */
    public function computeCancellationFee(
        Reservation $reservation,
        CancellationPolicy $policy,
        \DateTimeImmutable $now,
    ): array {
        $hoursBefore = $this->hoursBetween($now, $reservation->getCheckIn());

        if ($policy === CancellationPolicy::FLEXIBLE) {
            return [
                'amountXof'   => '0.00',
                'reason'      => 'Politique flexible : annulation gratuite.',
                'hoursBefore' => $hoursBefore,
            ];
        }

        if ($policy === CancellationPolicy::STRICT) {
            return [
                'amountXof'   => $this->normalize($reservation->getRateXof()),
                'reason'      => 'Politique stricte : 1ère nuit retenue.',
                'hoursBefore' => $hoursBefore,
            ];
        }

        // MODERATE
        if ($hoursBefore >= 48) {
            return [
                'amountXof'   => '0.00',
                'reason'      => 'Annulation > 48 h avant l\'arrivée : gratuit.',
                'hoursBefore' => $hoursBefore,
            ];
        }
        if ($hoursBefore >= 24) {
            return [
                'amountXof'   => $this->normalize($reservation->getRateXof()),
                'reason'      => 'Annulation 24-48 h avant l\'arrivée : 1ère nuit retenue.',
                'hoursBefore' => $hoursBefore,
            ];
        }

        return [
            'amountXof'   => $this->normalize($reservation->getTotalXof()),
            'reason'      => 'Annulation < 24 h avant l\'arrivée : total retenu.',
            'hoursBefore' => $hoursBefore,
        ];
    }

    /**
     * Nombre d'heures entières entre $from et $to.
     * Retourne 0 si $to <= $from (annulation après le check-in).
     */
    public function hoursBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }

        $seconds = $to->getTimestamp() - $from->getTimestamp();

        return (int) floor($seconds / 3600);
    }

    /**
     * Normalise un montant XOF en string bcmath (2 décimales).
     */
    private function normalize(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
