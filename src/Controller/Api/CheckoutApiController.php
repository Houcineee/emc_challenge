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
use Predis\ClientInterface;
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
        private readonly ClientInterface $redis,
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
        $code = strtoupper(trim((string) $data['code']));

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
        if ($eventId <= 0 || count($seatIds) !== count(array_unique($seatIds)) || (empty($seatIds) ? true : min($seatIds) <= 0)) {
            return new JsonResponse([
                'error' => 'Les identifiants de l’événement et des sièges sont invalides ou dupliqués.',
            ], Response::HTTP_BAD_REQUEST);
        }
        $couponCode = isset($data['couponCode']) && trim((string) $data['couponCode']) !== ''
            ? strtoupper(trim((string) $data['couponCode']))
            : null;

        // Idempotency Key handling (Header 'Idempotency-Key' or body field 'idempotencyKey')
        $idempotencyKey = trim((string) ($request->headers->get('Idempotency-Key') ?? ($data['idempotencyKey'] ?? '')));
        $idempRedisKey = null;

        if ($idempotencyKey !== '') {
            $idempRedisKey = sprintf('idemp:checkout:%d:%s', $user->getId(), substr($idempotencyKey, 0, 64));
            $cached = $this->redis->get($idempRedisKey);

            if ($cached !== null) {
                if ((string) $cached === 'PROCESSING') {
                    return new JsonResponse([
                        'error' => 'Une transaction identique est actuellement en cours de traitement. Veuillez patienter.',
                    ], Response::HTTP_CONFLICT);
                }

                $cachedPayload = json_decode((string) $cached, true);
                if (is_array($cachedPayload)) {
                    return new JsonResponse($cachedPayload, Response::HTTP_CREATED, [
                        'X-Idempotent-Replay' => 'true',
                    ]);
                }
            }

            // Lock idempotency key in PROCESSING state for 120 seconds
            $lockAcquired = $this->redis->set($idempRedisKey, 'PROCESSING', 'EX', 120, 'NX');
            if ($lockAcquired === null || (string) $lockAcquired !== 'OK') {
                return new JsonResponse([
                    'error' => 'Une transaction identique est actuellement en cours de traitement. Veuillez patienter.',
                ], Response::HTTP_CONFLICT);
            }
        }

        $event = $this->em->getRepository(Event::class)->find($eventId);
        if ($event === null) {
            if ($idempRedisKey !== null) {
                $this->redis->del([$idempRedisKey]);
            }

            return new JsonResponse(['error' => 'Événement non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $tickets = $this->ticketingService->purchase($event, $seatIds, $user, $couponCode);
        } catch (\Throwable $e) {
            if ($idempRedisKey !== null) {
                $this->redis->del([$idempRedisKey]);
            }

            if ($e instanceof HoldExpiredException) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_GONE);
            }
            if ($e instanceof InvalidCouponException || $e instanceof DomainException) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }
            if ($e instanceof UniqueConstraintViolationException) {
                return new JsonResponse([
                    'error' => 'Conflit d\'achat simultané détecté. La contrainte de base de données a empêché la double vente.',
                ], Response::HTTP_CONFLICT);
            }

            throw $e;
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

        $responsePayload = [
            'success' => true,
            'message' => 'Achat validé avec succès.',
            'tickets' => $formattedTickets,
        ];

        if ($idempRedisKey !== null) {
            // Cache successful checkout result for 24 hours
            $this->redis->set($idempRedisKey, json_encode($responsePayload), 'EX', 86400);
        }

        return new JsonResponse($responsePayload, Response::HTTP_CREATED);
    }
}
