<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Coupon;
use App\Entity\Event;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\EventStatus;
use App\Enum\TicketStatus;
use App\Enum\UserRole;
use App\Exception\HoldExpiredException;
use App\Exception\InvalidCouponException;
use App\Service\SeatHoldService;
use App\Service\TicketingService;
use Doctrine\ORM\EntityManagerInterface;
use Predis\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TicketingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SeatHoldService $seatHoldService;
    private TicketingService $ticketingService;
    private ClientInterface $redis;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->seatHoldService = $container->get(SeatHoldService::class);
        $this->ticketingService = $container->get(TicketingService::class);
        $this->redis = $container->get(ClientInterface::class);

        $this->clearRedisHolds();
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        $this->clearRedisHolds();
        parent::tearDown();
    }

    private function clearRedisHolds(): void
    {
        $keys = (array) $this->redis->keys('hold:event:*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }

    public function testSuccessfulPurchaseWithHold(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $user = $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::CLIENT]);

        $soldSeatIds = $this->getSoldSeatIdsForEvent($event);
        $seat = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Seat::class, 's')
            ->where('s.id NOT IN (:soldIds)')
            ->setParameter('soldIds', !empty($soldSeatIds) ? $soldSeatIds : [0])
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();

        // 1. Hold seat
        $this->seatHoldService->holdSeats($event, [$seat->getId()], $user, 900);

        // 2. Purchase
        $tickets = $this->ticketingService->purchase($event, [$seat->getId()], $user);

        // 3. Assertions
        $this->assertCount(1, $tickets);
        $ticket = $tickets[0];

        $this->assertSame(TicketStatus::PAID, $ticket->getStatus());
        $this->assertSame($event->getId(), $ticket->getEvent()->getId());
        $this->assertSame($seat->getId(), $ticket->getSeat()->getId());
        $this->assertSame($user->getId(), $ticket->getUser()->getId());

        // Price calculation assertion: basePrice * (100 + multiplier) / 100
        $expectedPrice = (int) round($event->getBasePriceCents() * (100 + $seat->getZone()->getPriceMultiplierPercent()) / 100);
        $this->assertSame($expectedPrice, $ticket->getFinalPriceCents());

        // Redis hold must be deleted after purchase
        $this->assertCount(0, $this->seatHoldService->getUserActiveHolds($event, $user));
    }

    public function testPurchaseWithoutHoldThrowsHoldExpiredException(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $user = $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::CLIENT]);

        $soldSeatIds = $this->getSoldSeatIdsForEvent($event);
        $seat = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Seat::class, 's')
            ->where('s.id NOT IN (:soldIds)')
            ->setParameter('soldIds', !empty($soldSeatIds) ? $soldSeatIds : [0])
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();

        // No hold placed -> must throw HoldExpiredException
        $this->expectException(HoldExpiredException::class);
        $this->ticketingService->purchase($event, [$seat->getId()], $user);
    }

    public function testPurchaseWithCouponAppliesDiscount(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $user = $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::CLIENT]);

        // Find or create a 20% coupon
        $coupon = $this->em->getRepository(Coupon::class)->findOneBy(['event' => $event]);
        if ($coupon === null) {
            $coupon = new Coupon();
            $coupon->setEvent($event);
            $coupon->setCode('TEST20');
            $coupon->setDiscountPercent(20);
            $coupon->setValidFrom(new \DateTimeImmutable('-1 day'));
            $coupon->setValidUntil(new \DateTimeImmutable('+10 days'));
            $coupon->setMaxUses(10);
            $this->em->persist($coupon);
            $this->em->flush();
        }

        $soldSeatIds = $this->getSoldSeatIdsForEvent($event);
        $seat = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Seat::class, 's')
            ->where('s.id NOT IN (:soldIds)')
            ->setParameter('soldIds', !empty($soldSeatIds) ? $soldSeatIds : [0])
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();

        $this->seatHoldService->holdSeats($event, [$seat->getId()], $user, 900);
        $tickets = $this->ticketingService->purchase($event, [$seat->getId()], $user, $coupon->getCode());

        $this->assertCount(1, $tickets);
        $basePrice = (int) round($event->getBasePriceCents() * (100 + $seat->getZone()->getPriceMultiplierPercent()) / 100);
        $expectedFinal = (int) round($basePrice * (100 - $coupon->getDiscountPercent()) / 100);

        $this->assertSame($expectedFinal, $tickets[0]->getFinalPriceCents());
        $this->assertSame($coupon->getId(), $tickets[0]->getCoupon()->getId());
    }

    public function testCancelEventRefundsAllTickets(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $this->assertNotNull($event);
        $originalStatus = $event->getStatus();

        // Put a hold on a seat to verify it gets released
        $user = $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::CLIENT]);
        $seat = $this->em->getRepository(Seat::class)->findOneBy([]);
        $key = sprintf('hold:event:%d:seat:%d', $event->getId(), $seat->getId());
        $this->redis->set($key, (string) $user->getId());

        try {
            // Cancel event
            $refundedCount = $this->ticketingService->cancelEvent($event);

            // Assert event is CANCELLED
            $this->assertSame(EventStatus::CANCELLED, $event->getStatus());

            // Assert all tickets for this event are now REFUNDED
            $unrefundedTickets = $this->em->createQueryBuilder()
                ->select('count(t.id)')
                ->from(Ticket::class, 't')
                ->where('t.event = :event')
                ->andWhere('t.status != :status')
                ->setParameter('event', $event)
                ->setParameter('status', TicketStatus::REFUNDED)
                ->getQuery()
                ->getSingleScalarResult();

            $this->assertSame(0, (int) $unrefundedTickets);

            // Assert Redis holds are cleared
            $holds = $this->redis->keys(sprintf('hold:event:%d:*', $event->getId()));
            $this->assertEmpty($holds);
        } finally {
            // Restore status so other test suites aren't deprived of scheduled events
            $event->setStatus($originalStatus);
            $this->em->flush();
        }
    }

    private function getSoldSeatIdsForEvent(Event $event): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('s.id')
            ->from(Ticket::class, 't')
            ->join('t.seat', 's')
            ->where('t.event = :event')
            ->andWhere('t.status = :status')
            ->setParameter('event', $event)
            ->setParameter('status', TicketStatus::PAID)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'id'));
    }
}
