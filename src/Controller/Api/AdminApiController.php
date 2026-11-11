<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Event;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Enum\TicketStatus;
use App\Service\TicketingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
