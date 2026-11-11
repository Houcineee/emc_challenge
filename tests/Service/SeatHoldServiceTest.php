<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\EventStatus;
use App\Enum\TicketStatus;
use App\Enum\UserRole;
use App\Exception\EventNotBookableException;
use App\Exception\MaxHoldsExceededException;
use App\Exception\SeatAlreadyHeldException;
use App\Exception\SeatAlreadySoldException;
use App\Service\SeatHoldService;
use Doctrine\ORM\EntityManagerInterface;
use Predis\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SeatHoldServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SeatHoldService $seatHoldService;
    private ClientInterface $redis;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->seatHoldService = $container->get(SeatHoldService::class);
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

    public function testHoldSeatsSuccess(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $this->assertNotNull($event);

        $user = $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::CLIENT]);
        $this->assertNotNull($user);

        // Find 2 unsold seats for this event
        $soldSeatIds = $this->getSoldSeatIdsForEvent($event);
        $availableSeats = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Seat::class, 's')
            ->where('s.id NOT IN (:soldIds)')
            ->setParameter('soldIds', !empty($soldSeatIds) ? $soldSeatIds : [0])
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        $this->assertCount(2, $availableSeats);
        $seatIds = array_map(static fn(Seat $s): int => $s->getId(), $availableSeats);

        // Hold seats
        $this->seatHoldService->holdSeats($event, $seatIds, $user, 900);

        // Verify holds in Redis
        $holds = $this->seatHoldService->getUserActiveHolds($event, $user);
        $this->assertCount(2, $holds);
        $heldSeatIds = array_column($holds, 'seatId');
        $this->assertContains($seatIds[0], $heldSeatIds);
        $this->assertContains($seatIds[1], $heldSeatIds);

        // Verify TTL is close to 900
        $this->assertGreaterThan(850, $holds[0]['ttl']);
    }

    public function testHoldSeatsFailsWhenAlreadyHeldByAnotherUser(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $users = $this->em->getRepository(User::class)->findBy(['role' => UserRole::CLIENT]);
        $this->assertGreaterThanOrEqual(2, count($users));

        $alice = $users[0];
        $bob = $users[1];

        $soldSeatIds = $this->getSoldSeatIdsForEvent($event);
        $seat = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Seat::class, 's')
            ->where('s.id NOT IN (:soldIds)')
            ->setParameter('soldIds', !empty($soldSeatIds) ? $soldSeatIds : [0])
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();

        // Alice holds the seat
        $this->seatHoldService->holdSeats($event, [$seat->getId()], $alice, 900);

        // Bob attempts to hold the same seat -> must throw SeatAlreadyHeldException
        $this->expectException(SeatAlreadyHeldException::class);
        $this->seatHoldService->holdSeats($event, [$seat->getId()], $bob, 900);
    }

    public function testHoldSeatsAllowsSameUserToReHold(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $alice = $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::CLIENT]);

        $soldSeatIds = $this->getSoldSeatIdsForEvent($event);
        $seat = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Seat::class, 's')
            ->where('s.id NOT IN (:soldIds)')
            ->setParameter('soldIds', !empty($soldSeatIds) ? $soldSeatIds : [0])
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();

        // Alice holds once
        $this->seatHoldService->holdSeats($event, [$seat->getId()], $alice, 900);

        // Alice holds again (e.g. refreshed page) -> should succeed without error
        $this->seatHoldService->holdSeats($event, [$seat->getId()], $alice, 900);

        $holds = $this->seatHoldService->getUserActiveHolds($event, $alice);
        $this->assertCount(1, $holds);
    }

    public function testAntiScalpingRuleMaxSixHolds(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $alice = $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::CLIENT]);

        $soldSeatIds = $this->getSoldSeatIdsForEvent($event);
        $seats = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Seat::class, 's')
            ->where('s.id NOT IN (:soldIds)')
            ->setParameter('soldIds', !empty($soldSeatIds) ? $soldSeatIds : [0])
            ->setMaxResults(7)
            ->getQuery()
            ->getResult();

        $this->assertCount(7, $seats);
        $seatIds = array_map(static fn(Seat $s): int => $s->getId(), $seats);

        // Attempt to hold 7 seats at once -> must throw MaxHoldsExceededException
        $this->expectException(MaxHoldsExceededException::class);
        $this->seatHoldService->holdSeats($event, $seatIds, $alice, 900);
    }

    public function testCannotHoldAlreadySoldSeat(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $alice = $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::CLIENT]);

        // Find a ticket that is already PAID
        $ticket = $this->em->getRepository(Ticket::class)->findOneBy([
            'event' => $event,
            'status' => TicketStatus::PAID,
        ]);

        if ($ticket === null) {
            $this->markTestSkipped('No pre-existing paid ticket found in fixtures for this event.');
        }

        $this->expectException(SeatAlreadySoldException::class);
        $this->seatHoldService->holdSeats($event, [$ticket->getSeat()->getId()], $alice, 900);
    }

    public function testCannotHoldPastOrUnscheduledEvent(): void
    {
        $cancelledEvent = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::CANCELLED]);
        $alice = $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::CLIENT]);

        if ($cancelledEvent === null) {
            $this->markTestSkipped('No cancelled event found in fixtures.');
        }

        $this->expectException(EventNotBookableException::class);
        $this->seatHoldService->holdSeats($cancelledEvent, [1], $alice, 900);
    }

    public function testReleaseSeat(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $alice = $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::CLIENT]);

        $soldSeatIds = $this->getSoldSeatIdsForEvent($event);
        $seat = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Seat::class, 's')
            ->where('s.id NOT IN (:soldIds)')
            ->setParameter('soldIds', !empty($soldSeatIds) ? $soldSeatIds : [0])
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();

        // Hold seat
        $this->seatHoldService->holdSeats($event, [$seat->getId()], $alice, 900);
        $this->assertCount(1, $this->seatHoldService->getUserActiveHolds($event, $alice));

        // Release seat
        $this->seatHoldService->releaseSeat($event, $seat->getId(), $alice);
        $this->assertCount(0, $this->seatHoldService->getUserActiveHolds($event, $alice));
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
