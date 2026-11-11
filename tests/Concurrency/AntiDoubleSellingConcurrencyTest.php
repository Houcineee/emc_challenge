<?php

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Entity\Event;
use App\Entity\Seat;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\EventStatus;
use App\Enum\TicketStatus;
use App\Enum\UserRole;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Validates the core Level 2 requirement:
 * "Une place se vend une seule fois par représentation, y compris quand deux clients achètent au même moment."
 *
 * Proves that MySQL's UNIQUE(event_id, seat_id) constraint acts as the ultimate transactional shield.
 */
class AntiDoubleSellingConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDatabaseUniqueConstraintPreventsDoubleSelling(): void
    {
        $event = $this->em->getRepository(Event::class)->findOneBy(['status' => EventStatus::SCHEDULED]);
        $this->assertNotNull($event);

        $users = $this->em->getRepository(User::class)->findBy(['role' => UserRole::CLIENT]);
        $this->assertGreaterThanOrEqual(2, count($users));
        $alice = $users[0];
        $bob = $users[1];

        // Find an unsold seat
        $soldSeatIds = $this->em->createQueryBuilder()
            ->select('s.id')
            ->from(Ticket::class, 't')
            ->join('t.seat', 's')
            ->where('t.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getScalarResult();
        $soldIds = !empty($soldSeatIds) ? array_map('intval', array_column($soldSeatIds, 'id')) : [0];

        $seat = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Seat::class, 's')
            ->where('s.id NOT IN (:soldIds)')
            ->setParameter('soldIds', $soldIds)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // 1. Alice purchases the seat
            $ticket1 = new Ticket();
            $ticket1->setEvent($event);
            $ticket1->setSeat($seat);
            $ticket1->setUser($alice);
            $ticket1->setFinalPriceCents(15000);
            $ticket1->setStatus(TicketStatus::PAID);

            $this->em->persist($ticket1);
            $this->em->flush();

            $this->assertNotNull($ticket1->getId());

            // 2. Bob attempts to purchase the exact same seat on the exact same event
            $ticket2 = new Ticket();
            $ticket2->setEvent($event);
            $ticket2->setSeat($seat);
            $ticket2->setUser($bob);
            $ticket2->setFinalPriceCents(15000);
            $ticket2->setStatus(TicketStatus::PAID);

            $this->expectException(UniqueConstraintViolationException::class);

            $this->em->persist($ticket2);
            $this->em->flush(); // Must throw UniqueConstraintViolationException!
        } finally {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
        }
    }
}
