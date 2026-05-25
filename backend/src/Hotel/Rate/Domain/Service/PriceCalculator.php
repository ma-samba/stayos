<?php

namespace App\Hotel\Rate\Domain\Service;

use App\Hotel\Rate\Domain\DTO\PriceQuote;
use App\Hotel\Rate\Domain\Entity\RatePlan;
use App\Hotel\Rate\Domain\Entity\SeasonalRate;
use App\Hotel\Rate\Domain\Enum\PromotionType;
use App\Hotel\Rate\Domain\Enum\SeasonalRateType;
use App\Hotel\Rate\Infrastructure\Repository\PromotionRepository;
use App\Hotel\Rate\Infrastructure\Repository\SeasonalRateRepository;
use App\Hotel\Room\Domain\Entity\RoomType;
use App\Shared\Exception\BusinessRuleException;
use Symfony\Component\Uid\Uuid;

class PriceCalculator
{
    public function __construct(
        private readonly SeasonalRateRepository $seasonalRateRepository,
        private readonly PromotionRepository    $promotionRepository,
    ) {}

    public function quote(
        Uuid               $hotelId,
        RoomType           $roomType,
        \DateTimeImmutable  $checkIn,
        \DateTimeImmutable  $checkOut,
        ?RatePlan           $ratePlan = null,
        ?string             $promoCode = null,
    ): PriceQuote {
        // Normaliser à minuit pour éviter les écarts de calcul
        // lorsque les bornes portent une heure (ex : réservation créée à 23h)
        $checkIn  = $checkIn->setTime(0, 0);
        $checkOut = $checkOut->setTime(0, 0);

        // 1. Nombre de nuits
        $nights = (int) $checkIn->diff($checkOut)->days;
        if ($nights < 1) {
            throw new BusinessRuleException('Séjour d\'au moins une nuit requis.');
        }

        // 2. Tarif de base TTC/nuit
        $baseRate = $this->resolveBaseRate($roomType, $ratePlan, $checkIn, $checkOut);

        // 3. Calcul par nuit (saisonnalité)
        $subtotal = '0.00';
        $breakdown = [];
        $appliedSeasonalRateName = null;

        for ($i = 0; $i < $nights; $i++) {
            $nightDate = $checkIn->modify("+{$i} days");
            $nightRate = $this->resolveNightRate($hotelId, $roomType, $nightDate, $baseRate, $appliedSeasonalRateName);

            $subtotal = bcadd($subtotal, $nightRate, 2);

            $breakdown[] = [
                'date' => $nightDate->format('Y-m-d'),
                'rate' => $nightRate,
            ];
        }

        // seasonalAdjustment = subtotal - (baseRate × nights)
        $flatTotal = bcmul($baseRate, (string) $nights, 2);
        $seasonalAdjustment = bcsub($subtotal, $flatTotal, 2);

        // 4. Promotion
        $discountXof = '0.00';
        $appliedPromotionCode = null;

        if ($promoCode !== null && $promoCode !== '') {
            [$discountXof, $appliedPromotionCode] = $this->applyPromotion(
                $hotelId,
                $promoCode,
                $subtotal,
                $nights,
                $checkIn,
                $roomType->getId(),
                $ratePlan?->getId(),
            );
        }

        // 5. Total = subtotal - discount, arrondi au FCFA entier
        $total = bcsub($subtotal, $discountXof, 2);

        // Arrondir au FCFA entier (pas de centimes en usage réel)
        $subtotal           = $this->roundXof($subtotal);
        $seasonalAdjustment = $this->roundXof($seasonalAdjustment);
        $discountXof        = $this->roundXof($discountXof);
        $total              = $this->roundXof($total);

        return new PriceQuote(
            baseRateXof:             $baseRate,
            nights:                  $nights,
            subtotalXof:             $subtotal,
            seasonalAdjustmentXof:   $seasonalAdjustment,
            discountXof:             $discountXof,
            totalXof:                $total,
            appliedSeasonalRateName: $appliedSeasonalRateName,
            appliedPromotionCode:    $appliedPromotionCode,
            appliedRatePlanId:       $ratePlan?->getId(),
            breakdown:               $breakdown,
        );
    }

    /**
     * Détermine le tarif de base : RatePlan si actif et couvrant la période, sinon RoomType.
     */
    private function resolveBaseRate(RoomType $roomType, ?RatePlan $ratePlan, \DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut): string
    {
        if ($ratePlan === null || !$ratePlan->isActive()) {
            return $roomType->getBaseRateXof();
        }

        $validFrom = $ratePlan->getValidFrom();
        $validTo   = $ratePlan->getValidTo();

        // Si le RatePlan a des dates de validité, vérifier qu'il couvre la période
        if ($validFrom !== null && $checkIn->format('Y-m-d') < $validFrom->format('Y-m-d')) {
            return $roomType->getBaseRateXof();
        }

        // checkOut -1 jour = dernière nuit
        $lastNight = $checkOut->modify('-1 day');
        if ($validTo !== null && $lastNight->format('Y-m-d') > $validTo->format('Y-m-d')) {
            return $roomType->getBaseRateXof();
        }

        return $ratePlan->getBaseRateXof();
    }

    /**
     * Résout le tarif d'une nuit spécifique en tenant compte de la saisonnalité.
     */
    private function resolveNightRate(
        Uuid      $hotelId,
        RoomType  $roomType,
        \DateTimeImmutable $nightDate,
        string    $baseRate,
        ?string   &$appliedSeasonalRateName,
    ): string {
        $seasonalRates = $this->seasonalRateRepository->findActiveForDate(
            $hotelId,
            $roomType->getId(),
            $nightDate,
        );

        if (empty($seasonalRates)) {
            return $baseRate;
        }

        /** @var SeasonalRate $best */
        $best = $seasonalRates[0]; // déjà trié par priority DESC

        if ($appliedSeasonalRateName === null) {
            $appliedSeasonalRateName = $best->getName();
        }

        return match ($best->getTypeEnum()) {
            SeasonalRateType::MULTIPLIER => bcmul($baseRate, $best->getValue(), 2),
            SeasonalRateType::ABSOLUTE   => $best->getValue(),
        };
    }

    /**
     * Applique une promotion et retourne [discountXof, appliedCode|null].
     *
     * @return array{string, ?string}
     */
    private function applyPromotion(
        Uuid    $hotelId,
        string  $promoCode,
        string  $subtotal,
        int     $nights,
        \DateTimeImmutable $checkIn,
        Uuid    $roomTypeId,
        ?Uuid   $ratePlanId,
    ): array {
        $promo = $this->promotionRepository->findOneActiveByCode($hotelId, $promoCode);

        if ($promo === null) {
            return ['0.00', null];
        }

        // Vérifications d'éligibilité
        if (!$promo->isValidOn($checkIn)) {
            return ['0.00', null];
        }

        if ($nights < $promo->getMinNights()) {
            return ['0.00', null];
        }

        $minAmount = $promo->getMinAmountXof();
        if ($minAmount !== null && bccomp($subtotal, $minAmount, 2) < 0) {
            return ['0.00', null];
        }

        if (!$promo->appliesToRoomType($roomTypeId)) {
            return ['0.00', null];
        }

        if (!$promo->appliesToRatePlan($ratePlanId)) {
            return ['0.00', null];
        }

        // Calcul de la réduction
        $discount = match ($promo->getTypeEnum()) {
            PromotionType::PERCENTAGE => bcdiv(bcmul($subtotal, $promo->getValue(), 4), '100', 2),
            PromotionType::FIXED      => $this->bcmin($promo->getValue(), $subtotal),
        };

        // Plafond
        $maxDiscount = $promo->getMaxDiscountXof();
        if ($maxDiscount !== null && bccomp($discount, $maxDiscount, 2) > 0) {
            $discount = $maxDiscount;
        }

        $appliedCode = bccomp($discount, '0.00', 2) > 0 ? $promoCode : null;

        return [$discount, $appliedCode];
    }

    /**
     * Arrondi au FCFA entier (demi supérieur) en bcmath pur, sans float.
     * Retourné en format ".00" pour cohérence colonnes decimal(10,2).
     */
    private function roundXof(string $amount): string
    {
        $neg     = bccomp($amount, '0', 2) < 0;
        $abs     = $neg ? bcmul($amount, '-1', 2) : $amount;
        $rounded = bcadd($abs, '0.5', 0);
        $result  = $neg ? bcmul($rounded, '-1', 0) : $rounded;

        return number_format((float) $result, 2, '.', '');
    }

    /**
     * Retourne le minimum de deux montants bcmath.
     */
    private function bcmin(string $a, string $b): string
    {
        return bccomp($a, $b, 2) <= 0 ? $a : $b;
    }
}
