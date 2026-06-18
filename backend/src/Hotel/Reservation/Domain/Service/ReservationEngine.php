<?php

namespace App\Hotel\Reservation\Domain\Service;

use App\Hotel\Billing\Domain\Service\FeeInvoiceService;
use App\Hotel\Billing\Domain\Service\InvoiceDraftService;
use App\Hotel\Guest\Infrastructure\Repository\GuestRepository;
use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
use App\Hotel\Housekeeping\Domain\Enum\CleaningType;
use App\Hotel\Housekeeping\Infrastructure\Repository\CleaningTaskRepository;
use App\Hotel\NightAudit\Domain\Service\BusinessDateService;
use App\Hotel\NightAudit\Domain\Service\DailyCloseLockChecker;
use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Rate\Domain\DTO\PriceQuote;
use App\Hotel\Rate\Domain\Service\PriceCalculator;
use App\Hotel\Rate\Infrastructure\Repository\PromotionRepository;
use App\Hotel\Rate\Infrastructure\Repository\RatePlanRepository;
use App\Hotel\Reservation\Application\DTO\CreateReservationDTO;
use App\Hotel\Reservation\Application\DTO\UpdateReservationDTO;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Domain\Enum\NoShowPolicy;
use App\Hotel\Reservation\Domain\Enum\ReservationStatus;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Mercure\MercurePublisher;
use App\Shared\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

class ReservationEngine
{
    public function __construct(
        private readonly ReservationRepository  $reservationRepository,
        private readonly RoomRepository         $roomRepository,
        private readonly GuestRepository        $guestRepository,
        private readonly ConflictChecker        $conflictChecker,
        private readonly AuditService           $auditService,
        private readonly MercurePublisher       $mercurePublisher,
        private readonly InvoiceDraftService    $invoiceDraftService,
        private readonly CleaningTaskRepository $cleaningTaskRepository,
        private readonly LoggerInterface        $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly PriceCalculator        $priceCalculator,
        private readonly RatePlanRepository     $ratePlanRepository,
        private readonly PromotionRepository    $promotionRepository,
        private readonly DailyCloseLockChecker  $closeLockChecker,
        private readonly BusinessDateService    $businessDateService,
        private readonly TenantContext          $tenantContext,
        private readonly ReservationFeeCalculator $feeCalculator,
        private readonly FeeInvoiceService      $feeInvoiceService,
    ) {}

    public function create(CreateReservationDTO $dto, ?StaffUser $staff): Reservation
    {
        $room = $this->roomRepository->find($dto->roomId)
            ?? throw new NotFoundHttpException('Chambre introuvable.');

        $guest = $this->guestRepository->find($dto->guestId)
            ?? throw new NotFoundHttpException('Client introuvable.');

        $tz = new \DateTimeZone('Africa/Dakar');
        $checkIn = new \DateTimeImmutable($dto->checkIn, $tz);
        $checkOut = new \DateTimeImmutable($dto->checkOut, $tz);

        // Garde-fou métier : refuse séjour entièrement passé et checkIn
        // très ancien. Cohérent avec les garde-fous checkIn et markNoShow.
        // À la création : on enforce la fenêtre 30j (nouvelle résa →
        // toujours dans la fenêtre raisonnable).
        $this->assertReservationDatesValid(
            $checkIn,
            $checkOut,
            enforceCheckInWindow: true,
        );

        // Le verrou night audit n'empêche pas la création d'une résa future :
        // on bloque uniquement la création rétroactive sur des nuits closes.
        $this->closeLockChecker->assertCanModifyDate($checkIn);

        $this->conflictChecker->assertAvailable((string) $room->getId(), $checkIn, $checkOut);

        $quote = $this->computeQuote($room, $checkIn, $checkOut, $dto->ratePlanId, $dto->promoCode, consumePromo: true);

        $reservation = new Reservation();
        $reservation
            ->setConfirmationNumber($this->reservationRepository->generateConfirmationNumber())
            ->setRoom($room)
            ->setGuest($guest)
            ->setCheckIn($checkIn)
            ->setCheckOut($checkOut)
            ->setAdults($dto->adults)
            ->setChildren($dto->children)
            ->setRateXof($quote->baseRateXof)
            ->setTotalXof($quote->totalXof)
            ->setPriceBreakdown($quote->toArray())
            ->setSource($dto->source)
            ->setStatusEnum(ReservationStatus::CONFIRMED);

        if ($dto->notes !== null) {
            $reservation->setNotes($dto->notes);
        }
        if ($dto->specialRequests !== null) {
            $reservation->setSpecialRequests($dto->specialRequests);
        }
        if ($dto->depositXof !== null) {
            $reservation->setDepositXof($dto->depositXof);
        }

        $this->entityManager->persist($reservation);

        $this->auditService->log(
            action:     'reservation.created',
            entityType: 'Reservation',
            entityId:   (string) $reservation->getId(),
            after:      [
                'confirmationNumber' => $reservation->getConfirmationNumber(),
                'room'               => $room->getNumber(),
                'guest'              => $guest->getFullName(),
                'checkIn'            => $dto->checkIn,
                'checkOut'           => $dto->checkOut,
                'totalXof'           => $quote->totalXof,
            ],
            staffUser: $staff,
        );

        $this->entityManager->flush();

        $this->mercurePublisher->publish('reservation.created', [
            'id'                 => (string) $reservation->getId(),
            'confirmationNumber' => $reservation->getConfirmationNumber(),
            'room'               => $room->getNumber(),
            'guest'              => $guest->getFullName(),
            'checkIn'            => $dto->checkIn,
            'checkOut'           => $dto->checkOut,
        ]);

        return $reservation;
    }

    public function update(Reservation $reservation, UpdateReservationDTO $dto, ?StaffUser $staff): Reservation
    {
        $blockedStatuses = [
            ReservationStatus::CHECKED_IN,
            ReservationStatus::CHECKED_OUT,
            ReservationStatus::CANCELLED,
        ];
        if (in_array($reservation->getStatusEnum(), $blockedStatuses, true)) {
            throw new BusinessRuleException(sprintf(
                'Impossible de modifier une réservation avec le statut "%s".',
                $reservation->getStatus()
            ));
        }

        // Verrou night audit : si l'une des nuits actuelles tombe dans une
        // journée close, modification interdite. Vérification également sur
        // la nouvelle date d'arrivée si elle change.
        $this->closeLockChecker->assertCanModifyDate($reservation->getCheckIn());
        if ($dto->checkIn !== null) {
            $this->closeLockChecker->assertCanModifyDate(
                new \DateTimeImmutable($dto->checkIn, new \DateTimeZone('Africa/Dakar'))
            );
        }

        $before = [
            'room'     => $reservation->getRoom()->getNumber(),
            'checkIn'  => $reservation->getCheckIn()->format('Y-m-d'),
            'checkOut' => $reservation->getCheckOut()->format('Y-m-d'),
            'adults'   => $reservation->getAdults(),
        ];

        $tz = new \DateTimeZone('Africa/Dakar');
        $checkIn  = $dto->checkIn  !== null ? new \DateTimeImmutable($dto->checkIn, $tz) : $reservation->getCheckIn();
        $checkOut = $dto->checkOut !== null ? new \DateTimeImmutable($dto->checkOut, $tz) : $reservation->getCheckOut();

        // Garde-fou métier : refuse séjour entièrement passé.
        // enforceCheckInWindow=true UNIQUEMENT si le checkIn est dans le
        // DTO (= modifié explicitement). Sinon on ne bloque pas la modif
        // d'une résa ancienne pour changer juste notes / adultes / chambre.
        $this->assertReservationDatesValid(
            $checkIn,
            $checkOut,
            enforceCheckInWindow: $dto->checkIn !== null,
        );

        $room = $reservation->getRoom();
        if ($dto->roomId !== null) {
            $room = $this->roomRepository->find($dto->roomId)
                ?? throw new NotFoundHttpException('Chambre introuvable.');
        }

        // Vérifier les conflits si dates ou chambre changent
        if ($dto->roomId !== null || $dto->checkIn !== null || $dto->checkOut !== null) {
            $this->conflictChecker->assertAvailable(
                (string) $room->getId(),
                $checkIn,
                $checkOut,
                (string) $reservation->getId(),
            );
        }

        if ($dto->roomId !== null) {
            $reservation->setRoom($room);
        }
        if ($dto->guestId !== null) {
            $guest = $this->guestRepository->find($dto->guestId)
                ?? throw new NotFoundHttpException('Client introuvable.');
            $reservation->setGuest($guest);
        }
        if ($dto->checkIn !== null) {
            $reservation->setCheckIn($checkIn);
        }
        if ($dto->checkOut !== null) {
            $reservation->setCheckOut($checkOut);
        }
        if ($dto->adults !== null) {
            $reservation->setAdults($dto->adults);
        }
        if ($dto->children !== null) {
            $reservation->setChildren($dto->children);
        }
        if ($dto->notes !== null) {
            $reservation->setNotes($dto->notes);
        }
        if ($dto->specialRequests !== null) {
            $reservation->setSpecialRequests($dto->specialRequests);
        }
        if ($dto->source !== null) {
            $reservation->setSource($dto->source);
        }
        if ($dto->depositXof !== null) {
            $reservation->setDepositXof($dto->depositXof);
        }

        // Recalculer le total si dates, chambre, ratePlan ou promoCode changent
        // Si ratePlanId/promoCode non fournis dans le DTO, recalcul sans promo/plan (comportement explicite)
        if ($dto->roomId !== null || $dto->checkIn !== null || $dto->checkOut !== null
            || $dto->ratePlanId !== null || $dto->promoCode !== null) {
            $quote = $this->computeQuote(
                $reservation->getRoom(),
                $reservation->getCheckIn(),
                $reservation->getCheckOut(),
                $dto->ratePlanId,
                $dto->promoCode,
                consumePromo: false,
            );
            $reservation->setRateXof($quote->baseRateXof);
            $reservation->setTotalXof($quote->totalXof);
            $reservation->setPriceBreakdown($quote->toArray());
        }

        $this->auditService->log(
            action:     'reservation.updated',
            entityType: 'Reservation',
            entityId:   (string) $reservation->getId(),
            before:     $before,
            after:      [
                'room'     => $reservation->getRoom()->getNumber(),
                'checkIn'  => $reservation->getCheckIn()->format('Y-m-d'),
                'checkOut' => $reservation->getCheckOut()->format('Y-m-d'),
                'adults'   => $reservation->getAdults(),
            ],
            staffUser: $staff,
        );

        $this->entityManager->flush();

        return $reservation;
    }

    /**
     * Annule une réservation en appliquant la politique d'annulation du
     * tenant. Si des frais sont dus, une facture distincte est émise
     * (statut ISSUED, lignes "Frais d'annulation").
     *
     * Le réceptionniste peut surcharger le montant via $feeOverrideXof
     * (geste commercial) — tracé dans l'audit log.
     *
     * @param string|null $feeOverrideXof Montant TTC en XOF, prioritaire
     *   sur le calcul auto. `'0'` = annulation gratuite forcée.
     *
     * @return array{
     *   reservation: Reservation,
     *   invoice: Invoice|null,
     *   feeXof: string,
     *   feeQuote: array{amountXof: string, reason: string, hoursBefore: int}
     * }
     */
    public function cancel(
        Reservation $reservation,
        string $reason,
        ?StaffUser $staff,
        ?string $feeOverrideXof = null,
    ): array {
        $blockedStatuses = [ReservationStatus::CHECKED_IN, ReservationStatus::CHECKED_OUT];
        if (in_array($reservation->getStatusEnum(), $blockedStatuses, true)) {
            throw new BusinessRuleException(sprintf(
                'Impossible d\'annuler une réservation avec le statut "%s".',
                $reservation->getStatus()
            ));
        }

        // Verrou night audit : annuler une résa dont une nuit tombe dans
        // une journée close réécrirait du passé comptable → refus.
        $this->closeLockChecker->assertCanModifyDate($reservation->getCheckIn());

        $tenant   = $this->tenantContext->get();
        $policy   = $tenant->getCancellationPolicy();
        $now      = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
        $feeQuote = $this->feeCalculator->computeCancellationFee($reservation, $policy, $now);
        $fee      = $feeOverrideXof !== null
            ? bcadd($feeOverrideXof, '0', 2)
            : $feeQuote['amountXof'];

        $invoice = null;
        if (bccomp($fee, '0', 2) > 0) {
            $description = sprintf(
                "Frais d'annulation (résa %s) — %s",
                $reservation->getConfirmationNumber(),
                $feeQuote['reason'],
            );
            $invoice = $this->feeInvoiceService->createFeeInvoice(
                $reservation,
                FeeInvoiceService::KIND_CANCELLATION,
                $fee,
                $description,
                $staff,
            );
        }

        $beforeStatus = $reservation->getStatus();

        $reservation->setStatusEnum(ReservationStatus::CANCELLED);
        $reservation->setNotes(
            ($reservation->getNotes() ? $reservation->getNotes() . "\n" : '')
            . sprintf('[Annulation] %s — policy=%s, fee=%s XOF', $reason, $policy->value, $fee)
        );

        // Libérer la chambre si elle était occupée (cas dégénéré : on
        // ne devrait pas atteindre ici si CHECKED_IN, déjà bloqué plus haut).
        if ($beforeStatus === ReservationStatus::CHECKED_IN->value) {
            $reservation->getRoom()->setStatusEnum(RoomStatus::AVAILABLE);
        }

        $this->auditService->log(
            action:     'reservation.cancelled',
            entityType: 'Reservation',
            entityId:   (string) $reservation->getId(),
            before:     ['status' => $beforeStatus],
            after:      [
                'status'        => ReservationStatus::CANCELLED->value,
                'reason'        => $reason,
                'policy'        => $policy->value,
                'feeXof'        => $fee,
                'feeOverridden' => $feeOverrideXof !== null,
                'invoiceId'     => $invoice !== null ? (string) $invoice->getId() : null,
            ],
            staffUser:  $staff,
        );

        $this->entityManager->flush();

        $this->mercurePublisher->publish('reservation.cancelled', [
            'id'                 => (string) $reservation->getId(),
            'confirmationNumber' => $reservation->getConfirmationNumber(),
            'room'               => $reservation->getRoom()->getNumber(),
            'reason'             => $reason,
        ]);

        return [
            'reservation' => $reservation,
            'invoice'     => $invoice,
            'feeXof'      => $fee,
            'feeQuote'    => $feeQuote,
        ];
    }

    /**
     * Marque la réservation no-show et applique la politique tenant
     * (ou l'override fourni par le réceptionniste).
     *
     * @return array{
     *   reservation: Reservation,
     *   invoice: Invoice|null,
     *   policy: string,
     *   feeXof: string
     * }
     */
    public function markNoShow(
        Reservation $reservation,
        ?StaffUser $staff,
        ?NoShowPolicy $policyOverride = null,
    ): array {
        $allowed = [ReservationStatus::CONFIRMED, ReservationStatus::PENDING];
        if (!in_array($reservation->getStatusEnum(), $allowed, true)) {
            throw new BusinessRuleException(sprintf(
                'Impossible de marquer no-show : statut actuel "%s" non autorisé.',
                $reservation->getStatus()
            ));
        }

        // Le no-show n'a de sens que pour une résa dont la date d'arrivée
        // est aujourd'hui ou passée. Le frontend filtre déjà via
        // `canMarkNoShow` mais une requête API directe contournerait.
        $today = $this->businessDateService->getCurrentBusinessDate();
        if ($reservation->getCheckIn() > $today) {
            throw new BusinessRuleException(sprintf(
                'Impossible de marquer no-show : la date d\'arrivée (%s) est dans le futur.',
                $reservation->getCheckIn()->format('Y-m-d'),
            ));
        }

        // Verrou night audit : la date concernée est checkIn. Si elle
        // tombe dans une journée close, opération refusée.
        $this->closeLockChecker->assertCanModifyDate($reservation->getCheckIn());

        $tenant = $this->tenantContext->get();
        $policy = $policyOverride ?? $tenant->getNoShowPolicy();
        $fee    = $this->feeCalculator->computeNoShowFee($reservation, $policy);

        $invoice = null;
        if (bccomp($fee, '0', 2) > 0) {
            $description = match ($policy) {
                NoShowPolicy::FIRST_NIGHT => sprintf(
                    'Frais de no-show (résa %s, 1ère nuit)',
                    $reservation->getConfirmationNumber(),
                ),
                NoShowPolicy::FULL => sprintf(
                    'Frais de no-show (résa %s, total séjour)',
                    $reservation->getConfirmationNumber(),
                ),
                default => sprintf(
                    'Frais de no-show (résa %s)',
                    $reservation->getConfirmationNumber(),
                ),
            };

            $invoice = $this->feeInvoiceService->createFeeInvoice(
                $reservation,
                FeeInvoiceService::KIND_NO_SHOW,
                $fee,
                $description,
                $staff,
            );
        }

        $beforeStatus = $reservation->getStatus();

        $reservation->setStatusEnum(ReservationStatus::NO_SHOW);
        $reservation->setNotes(
            ($reservation->getNotes() ? $reservation->getNotes() . "\n" : '')
            . sprintf('[No-show] policy=%s, fee=%s XOF', $policy->value, $fee)
        );

        $this->auditService->log(
            action:     'reservation.no_show',
            entityType: 'Reservation',
            entityId:   (string) $reservation->getId(),
            before:     ['status' => $beforeStatus],
            after:      [
                'status'    => ReservationStatus::NO_SHOW->value,
                'policy'    => $policy->value,
                'feeXof'    => $fee,
                'overridden'=> $policyOverride !== null,
                'invoiceId' => $invoice !== null ? (string) $invoice->getId() : null,
            ],
            staffUser:  $staff,
        );

        $this->entityManager->flush();

        $this->mercurePublisher->publish('reservation.no_show', [
            'id'                 => (string) $reservation->getId(),
            'confirmationNumber' => $reservation->getConfirmationNumber(),
            'room'               => $reservation->getRoom()->getNumber(),
        ]);

        return [
            'reservation' => $reservation,
            'invoice'     => $invoice,
            'policy'      => $policy->value,
            'feeXof'      => $fee,
        ];
    }

    public function checkIn(Reservation $reservation, ?string $notes, ?StaffUser $staff): Reservation
    {
        $allowedStatuses = [ReservationStatus::CONFIRMED, ReservationStatus::PENDING];
        if (!in_array($reservation->getStatusEnum(), $allowedStatuses, true)) {
            throw new BusinessRuleException(sprintf(
                'Impossible d\'enregistrer l\'arrivée : statut actuel "%s" non autorisé.',
                $reservation->getStatus()
            ));
        }

        // Garde-fou métier : refuser le check-in si le séjour est déjà
        // expiré sur le papier (today > checkOut prévu). Pattern symétrique
        // du garde-fou markNoShow (Sprint 14-A.2) qui refuse checkIn > today.
        // Sans cette protection, on enregistrait l'arrivée d'un client
        // dont le séjour était expiré depuis X jours — la résa aurait dû
        // être marquée no-show.
        // Comparaison `<` stricte : si checkOut == today, on autorise
        // (cas dégénéré du day-use, rare mais légitime).
        $today = $this->businessDateService->getCurrentBusinessDate();
        if ($reservation->getCheckOut() < $today) {
            throw new BusinessRuleException(sprintf(
                'Impossible d\'enregistrer l\'arrivée : le séjour prévu jusqu\'au %s est expiré. '
                . 'Marquer la réservation no-show ou modifier les dates.',
                $reservation->getCheckOut()->format('Y-m-d'),
            ));
        }

        // Défensif : refuse si la business date courante est déjà close
        // (ne devrait jamais arriver car on ne peut pas clôturer deux fois).
        $this->closeLockChecker->assertCanModifyDate($today);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));

        $reservation->setStatusEnum(ReservationStatus::CHECKED_IN);
        $reservation->setCheckedInAt($now);
        if ($notes !== null) {
            $reservation->setNotes(
                ($reservation->getNotes() ? $reservation->getNotes() . "\n" : '')
                . '[Check-in] ' . $notes
            );
        }

        // Passer la chambre en occupée
        $reservation->getRoom()->setStatusEnum(RoomStatus::OCCUPIED);

        // NB : la tâche de ménage DEPARTURE est créée au check-out réel
        // (voir checkOut()), pas ici — le ménage de départ est déclenché
        // par le départ effectif, daté du moment réel.
        $this->auditService->log(
            action:     'reservation.checkin',
            entityType: 'Reservation',
            entityId:   (string) $reservation->getId(),
            before:     ['status' => ReservationStatus::CONFIRMED->value],
            after:      ['status' => ReservationStatus::CHECKED_IN->value, 'checkedInAt' => $now->format('c')],
            staffUser:  $staff,
        );

        $this->entityManager->flush();

        $this->mercurePublisher->publish('reservation.checkin', [
            'id'                 => (string) $reservation->getId(),
            'confirmationNumber' => $reservation->getConfirmationNumber(),
            'room'               => $reservation->getRoom()->getNumber(),
        ]);

        return $reservation;
    }

    public function checkOut(Reservation $reservation, ?StaffUser $staff): Reservation
    {
        if ($reservation->getStatusEnum() !== ReservationStatus::CHECKED_IN) {
            throw new BusinessRuleException(sprintf(
                'Impossible d\'enregistrer le départ : statut actuel "%s" non autorisé.',
                $reservation->getStatus()
            ));
        }

        $this->closeLockChecker->assertCanModifyDate(
            $this->businessDateService->getCurrentBusinessDate()
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));

        $reservation->setStatusEnum(ReservationStatus::CHECKED_OUT);
        $reservation->setCheckedOutAt($now);

        // Passer la chambre en ménage
        $reservation->getRoom()->setStatusEnum(RoomStatus::CLEANING);

        // Créer la tâche de ménage DEPARTURE, datée du départ réel (maintenant),
        // pour qu'elle apparaîsse immédiatement dans le board du jour.
        // Garde anti-doublon : ne pas recréer si une tâche active existe déjà
        // pour cette chambre aujourd'hui (ex : recouche générée le matin).
        if (!$this->cleaningTaskRepository->hasActiveTaskForRoomOnDate((string) $reservation->getRoom()->getId(), $now)) {
            $task = new CleaningTask();
            $task->setRoom($reservation->getRoom());
            $task->setType(CleaningType::DEPARTURE->value);
            $task->setScheduledAt($now);
            $this->entityManager->persist($task);
        }

        // Incrémenter les séjours du client
        $guest = $reservation->getGuest();
        $guest->setTotalStays($guest->getTotalStays() + 1);

        $this->auditService->log(
            action:     'reservation.checkout',
            entityType: 'Reservation',
            entityId:   (string) $reservation->getId(),
            before:     ['status' => ReservationStatus::CHECKED_IN->value],
            after:      ['status' => ReservationStatus::CHECKED_OUT->value, 'checkedOutAt' => $now->format('c')],
            staffUser:  $staff,
        );

        $this->entityManager->flush();

        $this->mercurePublisher->publish('reservation.checkout', [
            'id'                 => (string) $reservation->getId(),
            'confirmationNumber' => $reservation->getConfirmationNumber(),
            'room'               => $reservation->getRoom()->getNumber(),
        ]);

        // Facture draft générée APRÈS le check-out (isolée et
        // non-bloquante : un échec facture n'annule pas le check-out)
        try {
            $this->invoiceDraftService->createFromReservation($reservation);
        } catch (\Throwable $e) {
            $this->logger->error('Échec génération facture draft au check-out', [
                'reservation' => (string) $reservation->getId(),
                'error'       => $e->getMessage(),
                'class'       => $e::class,
            ]);
        }

        return $reservation;
    }

    /**
     * Calcule le tarif via PriceCalculator et consomme éventuellement la promo.
     */
    private function computeQuote(
        Room               $room,
        \DateTimeImmutable  $checkIn,
        \DateTimeImmutable  $checkOut,
        ?string             $ratePlanId,
        ?string             $promoCode,
        bool                $consumePromo,
    ): PriceQuote {
        $hotelId = $this->resolveHotelId();

        $ratePlan = null;
        if ($ratePlanId !== null) {
            $ratePlan = $this->ratePlanRepository->find(Uuid::fromString($ratePlanId));
        }

        $quote = $this->priceCalculator->quote(
            $hotelId,
            $room->getType(),
            $checkIn,
            $checkOut,
            $ratePlan,
            $promoCode,
        );

        // Incrémenter usedCount si la promo a été effectivement appliquée
        if ($consumePromo && $quote->appliedPromotionCode !== null) {
            $promo = $this->promotionRepository->findOneActiveByCode($hotelId, $quote->appliedPromotionCode);
            if ($promo !== null && ($promo->getMaxUses() === null || $promo->getUsedCount() < $promo->getMaxUses())) {
                $promo->setUsedCount($promo->getUsedCount() + 1);
            }
        }

        return $quote;
    }

    /**
     * Garde-fou métier sur les dates d'une réservation à la création
     * ou à la modification. Pattern symétrique des garde-fous checkIn
     * (refuse si checkOut < today) et markNoShow (refuse si checkIn > today).
     *
     * Règles :
     * 1. checkOut >= today — le séjour doit au moins s'étendre jusqu'à
     *    aujourd'hui. Empêche la création d'une résa entièrement passée
     *    (-10j → -7j, aucun cas métier valide).
     *
     * 2. checkIn >= today - 30 jours (si $enforceCheckInWindow) — le
     *    checkIn ne doit pas être trop ancien. 30 jours couvre le walk-in
     *    tardif légitime ; au-delà c'est presque certainement une saisie
     *    erronée. Désactivable pour la modif d'une résa ancienne sans
     *    toucher à sa date d'arrivée.
     *
     * @throws BusinessRuleException si une règle est violée
     */
    private function assertReservationDatesValid(
        \DateTimeImmutable $checkIn,
        \DateTimeImmutable $checkOut,
        bool $enforceCheckInWindow,
    ): void {
        $today = $this->businessDateService->getCurrentBusinessDate();

        if ($checkOut < $today) {
            throw new BusinessRuleException(sprintf(
                'Impossible : le séjour (jusqu\'au %s) est déjà terminé. '
                . 'Vérifier les dates d\'arrivée et de départ.',
                $checkOut->format('Y-m-d'),
            ));
        }

        if ($enforceCheckInWindow) {
            $maxBackDays = 30;
            $oldestAllowedCheckIn = $today->modify("-{$maxBackDays} days");

            if ($checkIn < $oldestAllowedCheckIn) {
                throw new BusinessRuleException(sprintf(
                    'Impossible : la date d\'arrivée (%s) est trop ancienne '
                    . '(plus de %d jours dans le passé). Contacter l\'administrateur '
                    . 'pour une régularisation manuelle.',
                    $checkIn->format('Y-m-d'),
                    $maxBackDays,
                ));
            }
        }
    }

    private function resolveHotelId(): Uuid
    {
        // Un seul HotelProfile par schema tenant (pattern existant, cf. InvoiceService)
        $hotel = $this->entityManager->getRepository(HotelProfile::class)->findOneBy([]);

        if ($hotel === null) {
            throw new BusinessRuleException('Profil hôtel introuvable.');
        }

        return $hotel->getId();
    }
}
