<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Coupon;
use App\Entity\Event;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Enum\TicketStatus;
use App\Service\TicketingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin', name: 'api_admin_')]
class AdminApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketingService $ticketingService,
    ) {
    }

    #[Route('/events/{id}/cancel', name: 'cancel_event', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancelEvent(int $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse([
                'error' => 'Accès refusé. Action réservée aux administrateurs.',
            ], Response::HTTP_FORBIDDEN);
        }

        $event = $this->em->getRepository(Event::class)->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'Événement non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        $refundedCount = $this->ticketingService->cancelEvent($event);

        return new JsonResponse([
            'success' => true,
            'message' => 'Événement annulé.',
            'refundedTicketsCount' => $refundedCount,
        ], Response::HTTP_OK);
    }

    #[Route('/events', name: 'create_event', methods: ['POST'])]
    public function createEvent(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse([
                'error' => 'Accès refusé. Action réservée aux administrateurs.',
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Corps de requête JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $eventTypeId = (int) ($data['eventTypeId'] ?? 0);
        $basePriceCents = (int) ($data['basePriceCents'] ?? 0);
        $startsAtStr = (string) ($data['startsAt'] ?? '');

        if ($title === '' || $eventTypeId <= 0 || $basePriceCents <= 0 || $startsAtStr === '') {
            return new JsonResponse([
                'error' => 'Champs obligatoires manquants ou invalides (title, eventTypeId, basePriceCents, startsAt).',
            ], Response::HTTP_BAD_REQUEST);
        }

        $eventType = $this->em->getRepository(\App\Entity\EventType::class)->find($eventTypeId);
        if ($eventType === null) {
            return new JsonResponse(['error' => 'Type d\'événement introuvable.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $startsAt = new \DateTimeImmutable($startsAtStr);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Format de date invalide (ISO 8601 requis).'], Response::HTTP_BAD_REQUEST);
        }

        $event = new Event();
        $event->setTitle($title);
        $event->setDescription($description);
        $event->setEventType($eventType);
        $event->setBasePriceCents($basePriceCents);
        $event->setStartsAt($startsAt);
        $event->setStatus(\App\Enum\EventStatus::SCHEDULED);

        $this->em->persist($event);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Événement créé avec succès.',
            'event' => [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'description' => $event->getDescription(),
                'basePriceCents' => $event->getBasePriceCents(),
                'startsAt' => $event->getStartsAt()?->format(\DateTimeInterface::ATOM),
                'status' => $event->getStatus()?->value,
                'eventType' => [
                    'id' => $eventType->getId(),
                    'name' => $eventType->getName(),
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/coupons', name: 'create_coupon', methods: ['POST'])]
    public function createCoupon(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse([
                'error' => 'Accès refusé. Action réservée aux administrateurs.',
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Corps de requête JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $eventId = (int) ($data['eventId'] ?? 0);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $discountPercent = (int) ($data['discountPercent'] ?? 0);
        $maxUses = isset($data['maxUses']) && $data['maxUses'] !== null && (int) $data['maxUses'] > 0
            ? (int) $data['maxUses']
            : 1000;

        if ($eventId <= 0 || $code === '' || $discountPercent <= 0 || $discountPercent > 100) {
            return new JsonResponse([
                'error' => 'Champs obligatoires invalides (eventId, code, discountPercent entre 1 et 100).',
            ], Response::HTTP_BAD_REQUEST);
        }

        $event = $this->em->getRepository(Event::class)->find($eventId);
        if ($event === null) {
            return new JsonResponse(['error' => 'Événement non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        $existingCoupon = $this->em->getRepository(Coupon::class)->findOneBy([
            'event' => $event,
            'code' => $code,
        ]);
        if ($existingCoupon !== null) {
            return new JsonResponse([
                'error' => sprintf('Un coupon avec le code "%s" existe déjà pour cet événement.', $code),
            ], Response::HTTP_CONFLICT);
        }

        $validFromStr = $data['validFrom'] ?? null;
        $validUntilStr = $data['validUntil'] ?? null;

        $validFrom = $validFromStr ? new \DateTimeImmutable((string) $validFromStr) : new \DateTimeImmutable('-1 hour');
        $validUntil = $validUntilStr
            ? new \DateTimeImmutable((string) $validUntilStr)
            : ($event->getStartsAt() ?? new \DateTimeImmutable('+30 days'));

        $coupon = new Coupon();
        $coupon->setEvent($event);
        $coupon->setCode($code);
        $coupon->setDiscountPercent($discountPercent);
        $coupon->setMaxUses($maxUses);
        $coupon->setValidFrom($validFrom);
        $coupon->setValidUntil($validUntil);

        $this->em->persist($coupon);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Coupon créé avec succès.',
            'coupon' => [
                'id' => $coupon->getId(),
                'eventId' => $event->getId(),
                'eventTitle' => $event->getTitle(),
                'code' => $coupon->getCode(),
                'discountPercent' => $coupon->getDiscountPercent(),
                'maxUses' => $coupon->getMaxUses(),
                'validFrom' => $coupon->getValidFrom()?->format(\DateTimeInterface::ATOM),
                'validUntil' => $coupon->getValidUntil()?->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/coupons', name: 'list_coupons', methods: ['GET'])]
    public function listCoupons(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_CASHIER')) {
            return new JsonResponse([
                'error' => 'Accès refusé. Réservé aux administrateurs et guichetiers.',
            ], Response::HTTP_FORBIDDEN);
        }

        $eventId = $request->query->getInt('eventId');
        $criteria = $eventId > 0 ? ['event' => $eventId] : [];

        $coupons = $this->em->getRepository(Coupon::class)->findBy($criteria, ['id' => 'DESC']);

        $usedCountsRows = $this->em->createQueryBuilder()
            ->select('IDENTITY(t.coupon) as couponId, COUNT(t.id) as usedCount')
            ->from(Ticket::class, 't')
            ->where('t.coupon IS NOT NULL')
            ->andWhere('t.status = :status')
            ->setParameter('status', TicketStatus::PAID)
            ->groupBy('t.coupon')
            ->getQuery()
            ->getResult();

        $usedMap = [];
        foreach ($usedCountsRows as $row) {
            $usedMap[(int) $row['couponId']] = (int) $row['usedCount'];
        }

        $formatted = array_map(static function (Coupon $c) use ($usedMap): array {
            $cid = (int) $c->getId();

            return [
                'id' => $cid,
                'eventId' => $c->getEvent()?->getId(),
                'eventTitle' => $c->getEvent()?->getTitle(),
                'code' => $c->getCode(),
                'discountPercent' => $c->getDiscountPercent(),
                'maxUses' => $c->getMaxUses(),
                'usedCount' => $usedMap[$cid] ?? 0,
                'validFrom' => $c->getValidFrom()?->format(\DateTimeInterface::ATOM),
                'validUntil' => $c->getValidUntil()?->format(\DateTimeInterface::ATOM),
            ];
        }, $coupons);

        return new JsonResponse($formatted, Response::HTTP_OK);
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_CASHIER')) {
            return new JsonResponse([
                'error' => 'Accès refusé. Réservé aux administrateurs et guichetiers.',
            ], Response::HTTP_FORBIDDEN);
        }

        $totalRevenue = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(t.finalPriceCents), 0)')
            ->from(Ticket::class, 't')
            ->where('t.status = :status')
            ->setParameter('status', TicketStatus::PAID)
            ->getQuery()
            ->getSingleScalarResult();

        $totalTicketsSold = (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Ticket::class, 't')
            ->where('t.status = :status')
            ->setParameter('status', TicketStatus::PAID)
            ->getQuery()
            ->getSingleScalarResult();

        $totalSeatsInHall = (int) $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Seat::class, 's')
            ->getQuery()
            ->getSingleScalarResult();

        if ($totalSeatsInHall === 0) {
            $totalSeatsInHall = 400;
        }

        $eventStatsRows = $this->em->createQueryBuilder()
            ->select('IDENTITY(t.event) as eventId, COUNT(t.id) as soldCount, COALESCE(SUM(t.finalPriceCents), 0) as eventRevenue')
            ->from(Ticket::class, 't')
            ->where('t.status = :status')
            ->setParameter('status', TicketStatus::PAID)
            ->groupBy('t.event')
            ->getQuery()
            ->getResult();

        $statsByEventId = [];
        foreach ($eventStatsRows as $row) {
            $statsByEventId[(int) $row['eventId']] = [
                'soldCount' => (int) $row['soldCount'],
                'revenue' => (int) $row['eventRevenue'],
            ];
        }

        $events = $this->em->getRepository(Event::class)->findBy([], ['startsAt' => 'ASC']);

        $eventBreakdown = [];
        foreach ($events as $event) {
            $eventId = (int) $event->getId();
            $soldSeats = $statsByEventId[$eventId]['soldCount'] ?? 0;
            $revenueCents = $statsByEventId[$eventId]['revenue'] ?? 0;
            $occupancyPercent = $totalSeatsInHall > 0 ? round(($soldSeats / $totalSeatsInHall) * 100, 2) : 0.0;

            $eventBreakdown[] = [
                'eventId' => $eventId,
                'title' => $event->getTitle(),
                'status' => $event->getStatus()?->value,
                'startsAt' => $event->getStartsAt()?->format(\DateTimeInterface::ATOM),
                'totalSeats' => $totalSeatsInHall,
                'soldSeats' => $soldSeats,
                'occupancyPercent' => $occupancyPercent,
                'revenueCents' => $revenueCents,
            ];
        }

        return new JsonResponse([
            'totalRevenueCents' => $totalRevenue,
            'totalTicketsSold' => $totalTicketsSold,
            'totalSeatsInHall' => $totalSeatsInHall,
            'events' => $eventBreakdown,
        ], Response::HTTP_OK);
    }
}
