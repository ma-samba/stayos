<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Rate\Application\DTO\PromotionDTO;
use App\Hotel\Rate\Domain\Entity\Promotion;
use App\Hotel\Rate\Infrastructure\Repository\PromotionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/promotions', name: 'api_promotions_')]
#[IsGranted('ROLE_MANAGER')]
class PromotionController extends AbstractApiController
{
    public function __construct(
        private readonly PromotionRepository    $promotionRepository,
        private readonly ValidatorInterface     $validator,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $promotions = $this->promotionRepository->findAll();

        return $this->jsonSuccess($promotions, ['promotion:read']);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Promotion $promotion): JsonResponse
    {
        return $this->jsonSuccess($promotion, ['promotion:read']);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('FEATURE_revenue_management')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $dto = $this->hydrateDto(new PromotionDTO(), $data);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationError($errors);
        }

        $hotel = $this->resolveHotel();

        // Vérifier l'unicité du code parmi les promos actives
        $existing = $this->promotionRepository->findOneActiveByCode($hotel->getId(), $dto->code);
        if ($existing !== null) {
            return $this->jsonError(
                sprintf('Une promotion active avec le code "%s" existe déjà.', $dto->code),
                'ALREADY_EXISTS',
                409,
            );
        }

        $promo = new Promotion();
        $promo->setHotel($hotel);
        $promo->setCode($dto->code);
        $promo->setDescription($dto->description);
        $promo->setType($dto->type);
        $promo->setValue($dto->value);
        $promo->setMaxDiscountXof($dto->maxDiscountXof);
        $promo->setMinNights($dto->minNights);
        $promo->setMinAmountXof($dto->minAmountXof);
        $promo->setIsActive($dto->isActive);
        $promo->setMaxUses($dto->maxUses);
        $promo->setApplicableRoomTypeIds($dto->applicableRoomTypeIds);
        $promo->setApplicableRatePlanIds($dto->applicableRatePlanIds);

        if ($dto->validFrom !== null) {
            $promo->setValidFrom(new \DateTimeImmutable($dto->validFrom));
        }
        if ($dto->validTo !== null) {
            $promo->setValidTo(new \DateTimeImmutable($dto->validTo));
        }

        $this->entityManager->persist($promo);
        $this->entityManager->flush();

        return $this->jsonSuccess($promo, ['promotion:read'], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[IsGranted('FEATURE_revenue_management')]
    public function update(Promotion $promo, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $dto = $this->hydrateDto(new PromotionDTO(), $data);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationError($errors);
        }

        $hotel = $this->resolveHotel();

        // Vérifier l'unicité du code si changé
        if ($dto->code !== $promo->getCode()) {
            $existing = $this->promotionRepository->findOneActiveByCode($hotel->getId(), $dto->code);
            if ($existing !== null) {
                return $this->jsonError(
                    sprintf('Une promotion active avec le code "%s" existe déjà.', $dto->code),
                    'ALREADY_EXISTS',
                    409,
                );
            }
        }

        $promo->setCode($dto->code);
        $promo->setDescription($dto->description);
        $promo->setType($dto->type);
        $promo->setValue($dto->value);
        $promo->setMaxDiscountXof($dto->maxDiscountXof);
        $promo->setMinNights($dto->minNights);
        $promo->setMinAmountXof($dto->minAmountXof);
        $promo->setIsActive($dto->isActive);
        $promo->setMaxUses($dto->maxUses);
        $promo->setApplicableRoomTypeIds($dto->applicableRoomTypeIds);
        $promo->setApplicableRatePlanIds($dto->applicableRatePlanIds);
        $promo->setValidFrom($dto->validFrom !== null ? new \DateTimeImmutable($dto->validFrom) : null);
        $promo->setValidTo($dto->validTo !== null ? new \DateTimeImmutable($dto->validTo) : null);

        $this->entityManager->flush();

        return $this->jsonSuccess($promo, ['promotion:read']);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('FEATURE_revenue_management')]
    public function delete(Promotion $promo): JsonResponse
    {
        $promo->setIsActive(false);
        $this->entityManager->flush();

        return $this->jsonSuccess(null, [], 200);
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
