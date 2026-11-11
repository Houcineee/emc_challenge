<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tickets', name: 'api_tickets_')]
class TicketApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[Route('/my', name: 'my', methods: ['GET'])]
    public function myTickets(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $tickets = $this->em->createQueryBuilder()
            ->select('t', 'e', 's', 'z', 'c')
            ->from(Ticket::class, 't')
            ->join('t.event', 'e')
            ->join('t.seat', 's')
            ->join('s.zone', 'z')
            ->leftJoin('t.coupon', 'c')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();

        $formatted = array_map(static function (Ticket $t): array {
            $event = $t->getEvent();
            $seat = $t->getSeat();
            $zone = $seat->getZone();

            return [
                'id' => $t->getId(),
                'status' => $t->getStatus()?->value,
                'finalPriceCents' => $t->getFinalPriceCents(),
                'paidAt' => $t->getPaidAt()?->format(\DateTimeInterface::ATOM),
                'qrPayload' => sprintf('EMC-%d-%d', $event->getId(), $t->getId()),
                'event' => [
                    'id' => $event->getId(),
                    'title' => $event->getTitle(),
                    'startsAt' => $event->getStartsAt()?->format(\DateTimeInterface::ATOM),
                    'status' => $event->getStatus()?->value,
                ],
                'seat' => [
                    'id' => $seat->getId(),
                    'row' => $seat->getRowLabel(),
                    'number' => $seat->getSeatNumber(),
                    'zone' => $zone->getName()?->value,
                ],
                'coupon' => $t->getCoupon()?->getCode(),
            ];
        }, $tickets);

        return new JsonResponse($formatted, Response::HTTP_OK);
    }
}
