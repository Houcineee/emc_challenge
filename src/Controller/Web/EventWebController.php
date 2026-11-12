<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Configuration;
use App\Entity\Event;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Entity\Zone;
use App\Enum\EventStatus;
use App\Enum\TicketStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EventWebController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/', name: 'web_home', methods: ['GET'])]
    public function index(): Response
    {
        $events = $this->em->getRepository(Event::class)->findBy(
            ['status' => EventStatus::SCHEDULED],
            ['startsAt' => 'ASC']
        );

        $totalCapacity = 400;

        // Calcul des places vendues par événement
        $soldCountsRows = $this->em->createQueryBuilder()
            ->select('IDENTITY(t.event) as eventId, COUNT(t.id) as soldCount')
            ->from(Ticket::class, 't')
            ->where('t.status = :status')
            ->setParameter('status', TicketStatus::PAID)
            ->groupBy('t.event')
            ->getQuery()
            ->getResult();

        $soldCounts = [];
        foreach ($soldCountsRows as $row) {
            $soldCounts[(int) $row['eventId']] = (int) $row['soldCount'];
        }

        $eventsWithStats = [];
        foreach ($events as $event) {
            $eventId = (int) $event->getId();
            $soldCount = $soldCounts[$eventId] ?? 0;
            $occupancyPercent = $totalCapacity > 0 ? (int) round(($soldCount / $totalCapacity) * 100) : 0;
            $startingPriceMad = (int) round($event->getBasePriceCents() / 100);

            $eventsWithStats[] = [
                'entity' => $event,
                'soldCount' => $soldCount,
                'totalCapacity' => $totalCapacity,
                'occupancyPercent' => $occupancyPercent,
                'startingPriceMad' => $startingPriceMad,
            ];
        }

        return $this->render('events/index.html.twig', [
            'eventsWithStats' => $eventsWithStats,
        ]);
    }

    #[Route('/events/{id}', name: 'web_event_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $event = $this->em->getRepository(Event::class)->find($id);

        if ($event === null) {
            throw $this->createNotFoundException('Spectacle introuvable.');
        }

        $zones = $this->em->getRepository(Zone::class)->findBy([], ['id' => 'ASC']);

        $eventType = $event->getEventType();
        $configurations = $eventType !== null
            ? $this->em->getRepository(Configuration::class)->findBy(['eventType' => $eventType])
            : [];

        $isFrontStageStanding = false;
        $zoneConfigMap = [];

        foreach ($configurations as $cfg) {
            $zone = $cfg->getZone();
            if ($zone !== null) {
                $isSeated = $cfg->isSeated() ?? true;
                $zoneConfigMap[$zone->getId()] = $isSeated;
                if ($zone->getName()?->value === 'FRONT_STAGE' && !$isSeated) {
                    $isFrontStageStanding = true;
                }
            }
        }

        $zonePricing = [];
        foreach ($zones as $zone) {
            $priceCents = (int) round($event->getBasePriceCents() * (100 + $zone->getPriceMultiplierPercent()) / 100);
            $zonePricing[] = [
                'zone' => $zone,
                'priceCents' => $priceCents,
                'priceMad' => (int) round($priceCents / 100),
                'isSeated' => $zoneConfigMap[$zone->getId()] ?? true,
            ];
        }

        return $this->render('events/show.html.twig', [
            'event' => $event,
            'zones' => $zones,
            'zonePricing' => $zonePricing,
            'isFrontStageStanding' => $isFrontStageStanding,
            'startingPriceMad' => (int) round($event->getBasePriceCents() / 100),
        ]);
    }
}
