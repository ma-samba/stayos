<?php

namespace App\Hotel\Rate\Domain\DTO;

/**
 * Résultat immutable du calcul tarifaire.
 * Tous les montants sont en XOF TTC, string decimal.
 */
final readonly class PriceQuote
{
    public function __construct(
        public string  $baseRateXof,
        public int     $nights,
        public string  $subtotalXof,
        public string  $seasonalAdjustmentXof,
        public string  $discountXof,
        public string  $totalXof,
        public ?string $appliedSeasonalRateName,
        public ?string $appliedPromotionCode,
        public ?string $appliedRatePlanId,
        public array   $breakdown,
    ) {}

    public function toArray(): array
    {
        return [
            'baseRateXof'             => $this->baseRateXof,
            'nights'                  => $this->nights,
            'subtotalXof'             => $this->subtotalXof,
            'seasonalAdjustmentXof'   => $this->seasonalAdjustmentXof,
            'discountXof'             => $this->discountXof,
            'totalXof'                => $this->totalXof,
            'appliedSeasonalRateName' => $this->appliedSeasonalRateName,
            'appliedPromotionCode'    => $this->appliedPromotionCode,
            'appliedRatePlanId'       => $this->appliedRatePlanId,
            'breakdown'               => $this->breakdown,
        ];
    }
}
