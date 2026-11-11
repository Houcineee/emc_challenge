<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Coupon;
use App\Entity\Event;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\TicketStatus;
use App\Exception\DomainException;
use App\Exception\HoldExpiredException;
use App\Exception\InvalidCouponException;
use App\Service\TicketingService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CheckoutApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketingService $ticketingService,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/coupons/validate', name: 'api_coupons_validate', methods: ['POST'])]
    public function validateCoupon(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['eventId']) || empty($data['code'])) {
            return new JsonResponse([
                'valid' => false,
                'error' => 'Identifiant d\'événement et code coupon requis.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $eventId = (int) $data['eventId'];
        $code = trim((string) $data['code']);

        $event = $this->em->getRepository(Event::class)->find($eventId);
        if ($event === null) {
            return new JsonResponse([
                'valid' => false,
                'error' => 'Événement non trouvé.',
            ], Response::HTTP_NOT_FOUND);
        }

        $coupon = $this->em->getRepository(Coupon::class)->findOneBy([
            'event' => $event,
            'code' => $code,
        ]);

        if ($coupon === null) {
            return new JsonResponse([
                'valid' => false,
                'error' => 'Coupon invalide ou expiré.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $now = new \DateTimeImmutable();
        if ($coupon->getValidFrom() > $now || $coupon->getValidUntil() < $now) {
            return new JsonResponse([
                'valid' => false,
                'error' => 'Coupon invalide ou expiré.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($coupon->getMaxUses() !== null) {
            $usedCount = (int) $this->em->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(Ticket::class, 't')
                ->where('t.coupon = :coupon')
                ->andWhere('t.status = :status')
                ->setParameter('coupon', $coupon)
                ->setParameter('status', TicketStatus::PAID)
                ->getQuery()
                ->getSingleScalarResult();

            if ($usedCount >= $coupon->getMaxUses()) {
                return new JsonResponse([
                    'valid' => false,
                    'error' => 'Coupon invalide ou expiré.',
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        return new JsonResponse([
            'valid' => true,
            'code' => $coupon->getCode(),
            'discountPercent' => $coupon->getDiscountPercent(),
        ], Response::HTTP_OK);
    }

    #[Route('/api/checkout', name: 'api_checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['eventId']) || empty($data['seatIds']) || !is_array($data['seatIds'])) {
            return new JsonResponse([
                'error' => 'Données de paiement incomplètes (eventId et seatIds requis).',
            ], Response::HTTP_BAD_REQUEST);
        }

        $eventId = (int) $data['eventId'];
        $seatIds = array_map('intval', $data['seatIds']);
        $couponCode = isset($data['couponCode']) && trim((string) $data['couponCode']) !== ''
            ? trim((string) $data['couponCode'])
            : null;

        $event = $this->em->getRepository(Event::class)->find($eventId);
        if ($event === null) {
            return new JsonResponse(['error' => 'Événement non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $tickets = $this->ticketingService->purchase($event, $seatIds, $user, $couponCode);
        } catch (HoldExpiredException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_GONE);
        } catch (InvalidCouponException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse([
                'error' => 'Conflit d\'achat simultané détecté. La contrainte de base de données a empêché la double vente.',
            ], Response::HTTP_CONFLICT);
        }

        $formattedTickets = [];
        foreach ($tickets as $ticket) {
            $seat = $ticket->getSeat();

            $formattedTickets[] = [
                'id' => $ticket->getId(),
                'seatId' => $seat->getId(),
                'row' => $seat->getRowLabel(),
                'number' => $seat->getSeatNumber(),
                'zone' => $seat->getZone()?->getName()?->value,
                'finalPriceCents' => $ticket->getFinalPriceCents(),
                'status' => $ticket->getStatus()?->value,
                'qrPayload' => sprintf('EMC-%d-%d', $ticket->getEvent()?->getId(), $ticket->getId()),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Achat validé avec succès.',
            'tickets' => $formattedTickets,
        ], Response::HTTP_CREATED);
    }
}
