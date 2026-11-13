<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Event;
use App\Entity\Seat;
use App\Entity\User;
use App\Exception\DomainException;
use App\Exception\EventNotBookableException;
use App\Exception\MaxHoldsExceededException;
use App\Exception\SeatAlreadyHeldException;
use App\Exception\SeatAlreadySoldException;
use App\Service\SeatHoldService;
use Doctrine\ORM\EntityManagerInterface;
use Predis\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HoldApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeatHoldService $seatHoldService,
        private readonly Security $security,
        private readonly ClientInterface $redis,
    ) {
    }

    #[Route('/api/events/{id}/hold', name: 'api_events_hold', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function hold(int $id, Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $event = $this->em->getRepository(Event::class)->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'Événement non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['seatIds']) || !is_array($data['seatIds'])) {
            return new JsonResponse(['error' => 'Liste des sièges (seatIds) invalide ou vide.'], Response::HTTP_BAD_REQUEST);
        }

        $seatIds = array_map('intval', $data['seatIds']);
        if (count($seatIds) !== count(array_unique($seatIds)) || min($seatIds) <= 0) {
            return new JsonResponse([
                'error' => 'La liste des sièges contient des identifiants invalides ou dupliqués.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->seatHoldService->holdSeats($event, $seatIds, $user);
        } catch (SeatAlreadyHeldException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (SeatAlreadySoldException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (MaxHoldsExceededException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EventNotBookableException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Sièges réservés pendant 15 minutes.',
            'holds' => $this->seatHoldService->getUserActiveHolds($event, $user),
        ], Response::HTTP_OK);
    }

    #[Route('/api/events/{id}/hold/{seatId}', name: 'api_events_release_hold', methods: ['DELETE'], requirements: ['id' => '\d+', 'seatId' => '\d+'])]
    public function release(int $id, int $seatId): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $event = $this->em->getRepository(Event::class)->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'Événement non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        $this->seatHoldService->releaseSeat($event, $seatId, $user);

        return new JsonResponse([
            'success' => true,
            'message' => 'Siège libéré.',
        ], Response::HTTP_OK);
    }

    #[Route('/api/cart', name: 'api_cart', methods: ['GET'])]
    public function cart(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $pattern = 'hold:event:*:seat:*';
        $keys = (array) $this->redis->keys($pattern);

        $userHoldsByEvent = [];
        $seatIds = [];
        $eventIds = [];

        foreach ($keys as $key) {
            $keyStr = (string) $key;
            if (preg_match('/^hold:event:(\d+):seat:(\d+)$/', $keyStr, $matches)) {
                $eventId = (int) $matches[1];
                $seatId = (int) $matches[2];
                $owner = $this->redis->get($keyStr);

                if ($owner !== null && (string) $owner === (string) $user->getId()) {
                    $ttl = (int) $this->redis->ttl($keyStr);
                    if ($ttl > 0) {
                        $userHoldsByEvent[$eventId][$seatId] = $ttl;
                        $seatIds[] = $seatId;
                        $eventIds[$eventId] = true;
                    }
                }
            }
        }

        if (empty($userHoldsByEvent)) {
            return new JsonResponse([
                'items' => [],
                'totalAmountCents' => 0,
                'totalItems' => 0,
            ], Response::HTTP_OK);
        }

        $events = $this->em->getRepository(Event::class)->findBy(['id' => array_keys($eventIds)]);
        $eventsById = [];
        foreach ($events as $event) {
            $eventsById[$event->getId()] = $event;
        }

        $seats = $this->em->createQueryBuilder()
            ->select('s', 'z')
            ->from(Seat::class, 's')
            ->join('s.zone', 'z')
            ->where('s.id IN (:seatIds)')
            ->setParameter('seatIds', array_unique($seatIds))
            ->getQuery()
            ->getResult();

        $seatsById = [];
        foreach ($seats as $seat) {
            $seatsById[$seat->getId()] = $seat;
        }

        $items = [];
        $totalAmountCents = 0;

        foreach ($userHoldsByEvent as $eventId => $seatMap) {
            $event = $eventsById[$eventId] ?? null;
            if ($event === null) {
                continue;
            }

            foreach ($seatMap as $seatId => $ttl) {
                $seat = $seatsById[$seatId] ?? null;
                if ($seat === null) {
                    continue;
                }

                $zone = $seat->getZone();
                $priceCents = (int) round($event->getBasePriceCents() * (100 + $zone->getPriceMultiplierPercent()) / 100);
                $totalAmountCents += $priceCents;

                $items[] = [
                    'eventId' => $event->getId(),
                    'eventTitle' => $event->getTitle(),
                    'seatId' => $seat->getId(),
                    'row' => $seat->getRowLabel(),
                    'number' => $seat->getSeatNumber(),
                    'zone' => $zone->getName()?->value,
                    'priceCents' => $priceCents,
                    'ttl' => $ttl,
                ];
            }
        }

        return new JsonResponse([
            'items' => $items,
            'totalAmountCents' => $totalAmountCents,
            'totalItems' => count($items),
        ], Response::HTTP_OK);
    }
}
