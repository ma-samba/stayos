<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Rate\Application\DTO\QuoteRequestDTO;
use App\Hotel\Rate\Application\DTO\RatePlanDTO;
use App\Hotel\Rate\Application\DTO\SeasonalRateDTO;
use App\Hotel\Rate\Domain\Entity\RatePlan;
use App\Hotel\Rate\Domain\Entity\SeasonalRate;
use App\Hotel\Rate\Domain\Service\PriceCalculator;
use App\Hotel\Rate\Infrastructure\Repository\RatePlanRepository;
use App\Hotel\Rate\Infrastructure\Repository\SeasonalRateRepository;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Room\Infrastructure\Repository\RoomTypeRepository;
use App\Hotel\Shared\Domain\Service\FeatureChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/rates', name: 'api_rates_')]
#[IsGranted('ROLE_MANAGER')]
class RateController extends AbstractApiController
{
    public function __construct(
        private readonly RatePlanRepository     $ratePlanRepository,
        private readonly SeasonalRateRepository $seasonalRateRepository,
        private readonly RoomRepository         $roomRepository,
        private readonly RoomTypeRepository     $roomTypeRepository,
        private readonly PriceCalculator        $priceCalculator,
        private readonly FeatureChecker         $featureChecker,
        private readonly ValidatorInterface     $validator,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    // ── RatePlan CRUD ──

    #[Route('/plans', name: 'plans_index', methods: ['GET'])]
    public function listPlans(): JsonResponse
    {
        $plans = $this->ratePlanRepository->findAll();

        return $this->jsonSuccess($plans, ['rate:read']);
    }

    #[Route('/plans', name: 'plans_create', methods: ['POST'])]
    public function createPlan(Request $request): JsonResponse
    {
        $this->featureChecker->assertEnabled('revenue_management');

        $data = json_decode($request->getContent(), true) ?? [];
        $dto = $this->hydrateDto(new RatePlanDTO(), $data);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationError($errors);
        }

        $hotel = $this->resolveHotel();

        $plan = new RatePlan();
        $plan->setHotel($hotel);
        $plan->setName($dto->name);
        $plan->setBaseRateXof($dto->baseRateXof);
        $plan->setIsActive($dto->isActive);

        if ($dto->roomTypeId !== null) {
            $roomType = $this->roomTypeRepository->find($dto->roomTypeId);
            if ($roomType === null) {
                return $this->jsonError('Type de chambre introuvable.', 'NOT_FOUND', 404);
            }
            $plan->setRoomType($roomType);
        }
        if ($dto->minNights !== null) {
            $plan->setMinNights($dto->minNights);
        }
        if ($dto->validFrom !== null) {
            $plan->setValidFrom(new \DateTimeImmutable($dto->validFrom));
        }
        if ($dto->validTo !== null) {
            $plan->setValidTo(new \DateTimeImmutable($dto->validTo));
        }

        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return $this->jsonSuccess($plan, ['rate:read'], 201);
    }

    #[Route('/plans/{id}', name: 'plans_update', methods: ['PUT'])]
    public function updatePlan(RatePlan $plan, Request $request): JsonResponse
    {
        $this->featureChecker->assertEnabled('revenue_management');

        $data = json_decode($request->getContent(), true) ?? [];
        $dto = $this->hydrateDto(new RatePlanDTO(), $data);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationError($errors);
        }

        $plan->setName($dto->name);
        $plan->setBaseRateXof($dto->baseRateXof);
        $plan->setIsActive($dto->isActive);

        if ($dto->roomTypeId !== null) {
            $roomType = $this->roomTypeRepository->find($dto->roomTypeId);
            if ($roomType === null) {
                return $this->jsonError('Type de chambre introuvable.', 'NOT_FOUND', 404);
            }
            $plan->setRoomType($roomType);
        } else {
            $plan->setRoomType(null);
        }
        if ($dto->minNights !== null) {
            $plan->setMinNights($dto->minNights);
        }
        $plan->setValidFrom($dto->validFrom !== null ? new \DateTimeImmutable($dto->validFrom) : null);
        $plan->setValidTo($dto->validTo !== null ? new \DateTimeImmutable($dto->validTo) : null);

        $this->entityManager->flush();

        return $this->jsonSuccess($plan, ['rate:read']);
    }

    #[Route('/plans/{id}', name: 'plans_delete', methods: ['DELETE'])]
    public function deletePlan(RatePlan $plan): JsonResponse
    {
        $this->featureChecker->assertEnabled('revenue_management');

        $plan->setIsActive(false);
        $this->entityManager->flush();

        return $this->jsonSuccess(null, [], 200);
    }

    // ── SeasonalRate CRUD ──

    #[Route('/seasonal', name: 'seasonal_index', methods: ['GET'])]
    public function listSeasonal(): JsonResponse
    {
        $rates = $this->seasonalRateRepository->findAll();

        return $this->jsonSuccess($rates, ['rate:read']);
    }

    #[Route('/seasonal', name: 'seasonal_create', methods: ['POST'])]
    public function createSeasonal(Request $request): JsonResponse
    {
        $this->featureChecker->assertEnabled('revenue_management');

        $data = json_decode($request->getContent(), true) ?? [];
        $dto = $this->hydrateDto(new SeasonalRateDTO(), $data);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationError($errors);
        }

        if ($dto->endDate < $dto->startDate) {
            return $this->jsonError('La date de fin doit être postérieure ou égale à la date de début.', 'VALIDATION_ERROR', 422);
        }

        $hotel = $this->resolveHotel();

        $rate = new SeasonalRate();
        $rate->setHotel($hotel);
        $rate->setName($dto->name);
        $rate->setType($dto->type);
        $rate->setValue($dto->value);
        $rate->setStartDate(new \DateTimeImmutable($dto->startDate));
        $rate->setEndDate(new \DateTimeImmutable($dto->endDate));
        $rate->setPriority($dto->priority);
        $rate->setIsActive($dto->isActive);

        if ($dto->roomTypeId !== null) {
            $roomType = $this->roomTypeRepository->find($dto->roomTypeId);
            if ($roomType === null) {
                return $this->jsonError('Type de chambre introuvable.', 'NOT_FOUND', 404);
            }
            $rate->setRoomType($roomType);
        }

        $this->entityManager->persist($rate);
        $this->entityManager->flush();

        return $this->jsonSuccess($rate, ['rate:read'], 201);
    }

    #[Route('/seasonal/{id}', name: 'seasonal_update', methods: ['PUT'])]
    public function updateSeasonal(SeasonalRate $rate, Request $request): JsonResponse
    {
        $this->featureChecker->assertEnabled('revenue_management');

        $data = json_decode($request->getContent(), true) ?? [];
        $dto = $this->hydrateDto(new SeasonalRateDTO(), $data);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationError($errors);
        }

        if ($dto->endDate < $dto->startDate) {
            return $this->jsonError('La date de fin doit être postérieure ou égale à la date de début.', 'VALIDATION_ERROR', 422);
        }

        $rate->setName($dto->name);
        $rate->setType($dto->type);
        $rate->setValue($dto->value);
        $rate->setStartDate(new \DateTimeImmutable($dto->startDate));
        $rate->setEndDate(new \DateTimeImmutable($dto->endDate));
        $rate->setPriority($dto->priority);
        $rate->setIsActive($dto->isActive);

        if ($dto->roomTypeId !== null) {
            $roomType = $this->roomTypeRepository->find($dto->roomTypeId);
            if ($roomType === null) {
                return $this->jsonError('Type de chambre introuvable.', 'NOT_FOUND', 404);
            }
            $rate->setRoomType($roomType);
        } else {
            $rate->setRoomType(null);
        }

        $this->entityManager->flush();

        return $this->jsonSuccess($rate, ['rate:read']);
    }

    #[Route('/seasonal/{id}', name: 'seasonal_delete', methods: ['DELETE'])]
    public function deleteSeasonal(SeasonalRate $rate): JsonResponse
    {
        $this->featureChecker->assertEnabled('revenue_management');

        $rate->setIsActive(false);
        $this->entityManager->flush();

        return $this->jsonSuccess(null, [], 200);
    }

    // ── Quote ──

    #[Route('/quote', name: 'quote', methods: ['POST'])]
    public function quote(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $dto = $this->hydrateDto(new QuoteRequestDTO(), $data);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationError($errors);
        }

        $room = $this->roomRepository->find($dto->roomId);
        if ($room === null) {
            return $this->jsonError('Chambre introuvable.', 'NOT_FOUND', 404);
        }

        $ratePlan = null;
        if ($dto->ratePlanId !== null) {
            $ratePlan = $this->ratePlanRepository->find($dto->ratePlanId);
        }

        $tz = new \DateTimeZone('Africa/Dakar');
        $hotel = $this->resolveHotel();

        $quote = $this->priceCalculator->quote(
            $hotel->getId(),
            $room->getType(),
            new \DateTimeImmutable($dto->checkIn, $tz),
            new \DateTimeImmutable($dto->checkOut, $tz),
            $ratePlan,
            $dto->promoCode,
        );

        return $this->jsonSuccess($quote->toArray());
    }

    // ── Helpers ──

    private function resolveHotel(): HotelProfile
    {
        $hotel = $this->entityManager->getRepository(HotelProfile::class)->findOneBy([]);

        if ($hotel === null) {
            throw new \RuntimeException('Profil hôtel introuvable.');
        }

        return $hotel;
    }

    private function hydrateDto(object $dto, array $data): object
    {
        foreach ($data as $key => $value) {
            if (property_exists($dto, $key)) {
                $dto->$key = $value;
            }
        }

        return $dto;
    }

    private function validationError(iterable $errors): JsonResponse
    {
        $messages = [];
        foreach ($errors as $error) {
            $messages[$error->getPropertyPath()] = $error->getMessage();
        }

        return $this->jsonError('Données invalides.', 'VALIDATION_ERROR', 422);
    }
}
