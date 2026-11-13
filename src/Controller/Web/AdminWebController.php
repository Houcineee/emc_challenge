<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Coupon;
use App\Entity\Event;
use App\Entity\EventType;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Enum\TicketStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminWebController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin', name: 'web_admin', methods: ['GET'])]
    public function dashboard(): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if (!$isAdmin) {
            return $this->render('admin/dashboard.html.twig', [
                'isAdmin' => false,
                'totalRevenueCents' => 0,
                'totalRevenueMad' => 0,
                'totalTicketsSold' => 0,
                'totalSeats' => 400,
                'globalOccupancyPercent' => 0,
                'events' => [],
                'coupons' => [],
                'eventTypes' => [],
            ]);
        }

        $totalRevenueCents = (int) $this->em->createQueryBuilder()
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

        $totalSeats = (int) $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Seat::class, 's')
            ->getQuery()
            ->getSingleScalarResult();

        if ($totalSeats === 0) {
            $totalSeats = 400;
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
                'revenueCents' => (int) $row['eventRevenue'],
            ];
        }

        $events = $this->em->getRepository(Event::class)->findBy([], ['startsAt' => 'ASC']);
        $eventsData = [];
        $totalPotentialSeats = count($events) * $totalSeats;

        foreach ($events as $event) {
            $eid = (int) $event->getId();
            $sold = $statsByEventId[$eid]['soldCount'] ?? 0;
            $revenueCents = $statsByEventId[$eid]['revenueCents'] ?? 0;
            $occupancyPercent = $totalSeats > 0 ? round(($sold / $totalSeats) * 100, 1) : 0.0;

            $eventsData[] = [
                'entity' => $event,
                'soldSeats' => $sold,
                'totalSeats' => $totalSeats,
                'occupancyPercent' => $occupancyPercent,
                'revenueMad' => (int) round($revenueCents / 100),
            ];
        }

        $globalOccupancyPercent = $totalPotentialSeats > 0
            ? round(($totalTicketsSold / $totalPotentialSeats) * 100, 1)
            : 0.0;

        $coupons = $this->em->getRepository(Coupon::class)->findBy([], ['id' => 'DESC']);
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

        $couponsData = [];
        foreach ($coupons as $c) {
            $cid = (int) $c->getId();
            $couponsData[] = [
                'entity' => $c,
                'usedCount' => $usedMap[$cid] ?? 0,
            ];
        }

        $eventTypes = $this->em->getRepository(EventType::class)->findAll();

        return $this->render('admin/dashboard.html.twig', [
            'isAdmin' => true,
            'totalRevenueCents' => $totalRevenueCents,
            'totalRevenueMad' => (int) round($totalRevenueCents / 100),
            'totalTicketsSold' => $totalTicketsSold,
            'totalSeats' => $totalSeats,
            'globalOccupancyPercent' => $globalOccupancyPercent,
            'events' => $eventsData,
            'coupons' => $couponsData,
            'eventTypes' => $eventTypes,
        ]);
    }
}
