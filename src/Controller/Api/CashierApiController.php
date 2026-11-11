<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Ticket;
use App\Enum\TicketStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/cashier', name: 'api_cashier_')]
class CashierApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/verify-ticket', name: 'verify_ticket', methods: ['POST'])]
    public function verifyTicket(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_CASHIER') && !$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse([
                'error' => 'Accès refusé. Réservé aux guichetiers et administrateurs.',
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Corps de requête JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $ticketId = 0;
        $qrPayload = trim((string) ($data['qrPayload'] ?? ''));

        if ($qrPayload !== '') {
            // Support format EMC-{eventId}-{ticketId} or raw ticketId
            if (preg_match('/^EMC-\d+-(\d+)$/', $qrPayload, $matches)) {
                $ticketId = (int) $matches[1];
            } elseif (ctype_digit($qrPayload)) {
                $ticketId = (int) $qrPayload;
            }
        } elseif (isset($data['ticketId'])) {
            $ticketId = (int) $data['ticketId'];
        }

        if ($ticketId <= 0) {
            return new JsonResponse([
                'valid' => false,
                'error' => 'Payload QR code ou identifiant de billet invalide.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $ticket = $this->em->getRepository(Ticket::class)->find($ticketId);
        if ($ticket === null) {
            return new JsonResponse([
                'valid' => false,
                'error' => 'Billet introuvable dans le système.',
            ], Response::HTTP_NOT_FOUND);
        }

        $event = $ticket->getEvent();
        $seat = $ticket->getSeat();
        $buyer = $ticket->getUser();

        if ($ticket->getStatus() === TicketStatus::REFUNDED) {
            return new JsonResponse([
                'valid' => false,
                'status' => 'REFUNDED',
                'message' => 'Ce billet a été annulé ou remboursé. Accès refusé.',
                'ticket' => [
                    'id' => $ticket->getId(),
                    'eventTitle' => $event->getTitle(),
                    'status' => 'REFUNDED',
                ],
            ], Response::HTTP_OK);
        }

        return new JsonResponse([
            'valid' => true,
            'status' => 'PAID',
            'message' => 'Billet valide. Accès autorisé à la salle.',
            'ticket' => [
                'id' => $ticket->getId(),
                'eventTitle' => $event->getTitle(),
                'eventDate' => $event->getStartsAt()?->format(\DateTimeInterface::ATOM),
                'seatRow' => $seat->getRowLabel(),
                'seatNumber' => $seat->getSeatNumber(),
                'zoneName' => $seat->getZone()?->getName()?->value,
                'buyerEmail' => $buyer->getEmail(),
                'finalPriceCents' => $ticket->getFinalPriceCents(),
                'paidAt' => $ticket->getPaidAt()?->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_OK);
    }
}
