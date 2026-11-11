<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Configuration;
use App\Entity\Event;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\EventStatus;
use App\Enum\TicketStatus;
use App\Service\SeatHoldService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/events', name: 'api_events_')]
class EventApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeatHoldService $seatHoldService,
        private readonly Security $security,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $events = $this->em->getRepository(Event::class)->findBy(
            ['status' => EventStatus::SCHEDULED],
            ['startsAt' => 'ASC']
        );

        $data = array_map(static function (Event $event): array {
            return [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'description' => $event->getDescription(),
                'startsAt' => $event->getStartsAt()?->format(\DateTimeInterface::ATOM),
                'basePriceCents' => $event->getBasePriceCents(),
                'status' => $event->getStatus()?->value,
                'eventType' => [
                    'id' => $event->getEventType()?->getId(),
                    'name' => $event->getEventType()?->getName(),
                ],
            ];
        }, $events);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $event = $this->em->getRepository(Event::class)->find($id);

        if ($event === null) {
            return new JsonResponse(['error' => 'Événement non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id' => $event->getId(),
            'title' => $event->getTitle(),
            'description' => $event->getDescription(),
            'startsAt' => $event->getStartsAt()?->format(\DateTimeInterface::ATOM),
            'basePriceCents' => $event->getBasePriceCents(),
            'status' => $event->getStatus()?->value,
            'eventType' => [
                'id' => $event->getEventType()?->getId(),
                'name' => $event->getEventType()?->getName(),
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/{id}/seats', name: 'seats', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function seats(int $id): JsonResponse
    {
        $event = $this->em->getRepository(Event::class)->find($id);

        if ($event === null) {
            return new JsonResponse(['error' => 'Événement non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        $eventType = $event->getEventType();
        $zoneConfigurationMap = [];
        if ($eventType !== null) {
            $configs = $this->em->getRepository(Configuration::class)->findBy(['eventType' => $eventType]);
            foreach ($configs as $config) {
                if ($config->getZone() !== null) {
                    $zoneConfigurationMap[$config->getZone()->getId()] = $config->isSeated() ?? true;
                }
            }
        }

        $seats = $this->em->createQueryBuilder()
            ->select('s', 'z')
            ->from(Seat::class, 's')
            ->join('s.zone', 'z')
            ->orderBy('z.id', 'ASC')
            ->addOrderBy('s.rowLabel', 'ASC')
            ->addOrderBy('s.seatNumber', 'ASC')
            ->getQuery()
            ->getResult();

        $soldTickets = $this->em->createQueryBuilder()
            ->select('s.id')
            ->from(Ticket::class, 't')
            ->join('t.seat', 's')
            ->where('t.event = :event')
            ->andWhere('t.status = :status')
            ->setParameter('event', $event)
            ->setParameter('status', TicketStatus::PAID)
            ->getQuery()
            ->getScalarResult();

        $soldSeatIds = [];
        foreach ($soldTickets as $t) {
            $soldSeatIds[(int) $t['id']] = true;
        }

        $currentUser = $this->security->getUser();
        $userEntity = $currentUser instanceof User ? $currentUser : null;
        $heldInfo = $this->seatHoldService->getHeldSeatInfo($event, $userEntity);

        $formattedSeats = [];
        foreach ($seats as $seat) {
            $seatId = (int) $seat->getId();
            $zone = $seat->getZone();
            $zoneId = $zone->getId();

            $isSeated = $zoneConfigurationMap[$zoneId] ?? true;
            $basePrice = (int) round($event->getBasePriceCents() * (100 + $zone->getPriceMultiplierPercent()) / 100);

            if (isset($soldSeatIds[$seatId])) {
                $status = 'SOLD';
                $holdTtl = null;
            } elseif (isset($heldInfo[$seatId])) {
                $status = $heldInfo[$seatId]['isHeldByMe'] ? 'HELD_BY_ME' : 'HELD_BY_OTHER';
                $holdTtl = $heldInfo[$seatId]['ttl'] ?? null;
            } else {
                $status = 'AVAILABLE';
                $holdTtl = null;
            }

            $formattedSeats[] = [
                'id' => $seatId,
                'row' => $seat->getRowLabel(),
                'number' => $seat->getSeatNumber(),
                'zone' => [
                    'id' => $zoneId,
                    'name' => $zone->getName()?->value,
                    'multiplier' => $zone->getPriceMultiplierPercent(),
                ],
                'isSeated' => $isSeated,
                'priceCents' => $basePrice,
                'status' => $status,
                'holdTtl' => $holdTtl,
            ];
        }

        return new JsonResponse([
            'event' => [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'basePriceCents' => $event->getBasePriceCents(),
                'startsAt' => $event->getStartsAt()?->format(\DateTimeInterface::ATOM),
            ],
            'seats' => $formattedSeats,
        ], Response::HTTP_OK);
    }
}
