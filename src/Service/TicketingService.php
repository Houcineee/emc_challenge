<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coupon;
use App\Entity\Event;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\EventStatus;
use App\Enum\TicketStatus;
use App\Exception\DomainException;
use App\Exception\EventNotBookableException;
use App\Exception\HoldExpiredException;
use App\Exception\InvalidCouponException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class TicketingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeatHoldService $seatHoldService,
    ) {
    }

    /**
     * @param int[] $seatIds
     * @return list<Ticket>
     */
    public function purchase(Event $event, array $seatIds, User $user, ?string $couponCode = null): array
    {
        if ($event->getStatus() !== EventStatus::SCHEDULED) {
            throw new EventNotBookableException("Cet événement n'est pas disponible à la réservation.");
        }

        if (!$this->seatHoldService->verifyUserHolds($event, $seatIds, $user)) {
            throw new HoldExpiredException('La réservation temporaire de 15 minutes a expiré pour un ou plusieurs sièges.');
        }

        /** @var list<Ticket> $createdTickets */
        $createdTickets = $this->em->wrapInTransaction(function () use ($event, $seatIds, $user, $couponCode): array {
            $coupon = null;
            $discountPercent = 0;

            if ($couponCode !== null && trim($couponCode) !== '') {
                $coupon = $this->em->createQueryBuilder()
                    ->select('c')
                    ->from(Coupon::class, 'c')
                    ->where('c.event = :event')
                    ->andWhere('c.code = :code')
                    ->setParameter('event', $event)
                    ->setParameter('code', trim($couponCode))
                    ->getQuery()
                    ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                    ->getOneOrNullResult();

                if ($coupon === null) {
                    throw new InvalidCouponException('Code coupon invalide.');
                }

                $now = new \DateTimeImmutable();
                if ($coupon->getValidFrom() > $now || $coupon->getValidUntil() < $now) {
                    throw new InvalidCouponException("Ce coupon a expiré ou n'est pas encore actif.");
                }

                if ($coupon->getMaxUses() !== null) {
                    $usedCount = (int) $this->em->createQueryBuilder()
                        ->select('count(t.id)')
                        ->from(Ticket::class, 't')
                        ->where('t.coupon = :coupon')
                        ->andWhere('t.status = :status')
                        ->setParameter('coupon', $coupon)
                        ->setParameter('status', TicketStatus::PAID)
                        ->getQuery()
                        ->getSingleScalarResult();

                    if ($usedCount >= $coupon->getMaxUses()) {
                        throw new InvalidCouponException("Ce coupon a atteint sa limite d'utilisation.");
                    }
                }

                $discountPercent = $coupon->getDiscountPercent() ?? 0;
            }

            $tickets = [];
            foreach ($seatIds as $seatId) {
                $seat = $this->em->find(Seat::class, $seatId);
                if ($seat === null) {
                    throw new DomainException(sprintf('Le siège #%d n\'existe pas.', $seatId));
                }

                $basePrice = (int) round($event->getBasePriceCents() * (100 + $seat->getZone()->getPriceMultiplierPercent()) / 100);
                $finalPrice = (int) round($basePrice * (100 - $discountPercent) / 100);

                $ticket = new Ticket();
                $ticket->setEvent($event);
                $ticket->setSeat($seat);
                $ticket->setUser($user);
                $ticket->setCoupon($coupon);
                $ticket->setFinalPriceCents($finalPrice);
                $ticket->setStatus(TicketStatus::PAID);
                $ticket->setPaidAt(new \DateTimeImmutable());

                if (method_exists($ticket, 'setPurchasedAt')) {
                    $ticket->setPurchasedAt(new \DateTimeImmutable());
                }

                $this->em->persist($ticket);
                $tickets[] = $ticket;
            }

            $this->em->flush();

            return $tickets;
        });

        $this->seatHoldService->releaseHoldsForPurchase($event, $seatIds);

        return $createdTickets;
    }

    public function cancelEvent(Event $event): int
    {
        $refundedCount = $this->em->wrapInTransaction(function () use ($event): int {
            $event->setStatus(EventStatus::CANCELLED);

            /** @var list<Ticket> $tickets */
            $tickets = $this->em->createQueryBuilder()
                ->select('t')
                ->from(Ticket::class, 't')
                ->where('t.event = :event')
                ->andWhere('t.status = :status')
                ->setParameter('event', $event)
                ->setParameter('status', TicketStatus::PAID)
                ->getQuery()
                ->getResult();

            foreach ($tickets as $ticket) {
                $ticket->setStatus(TicketStatus::REFUNDED);
            }

            $this->em->flush();

            return count($tickets);
        });

        $this->seatHoldService->clearAllHoldsForEvent($event);

        return $refundedCount;
    }
}
