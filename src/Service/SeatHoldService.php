<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\EventStatus;
use App\Enum\TicketStatus;
use App\Enum\UserRole;
use App\Exception\DomainException;
use App\Exception\EventNotBookableException;
use App\Exception\MaxHoldsExceededException;
use App\Exception\SeatAlreadyHeldException;
use App\Exception\SeatAlreadySoldException;
use Doctrine\ORM\EntityManagerInterface;
use Predis\ClientInterface;

class SeatHoldService
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param int[] $seatIds
     */
    public function holdSeats(Event $event, array $seatIds, User $user, int $ttl = 900): void
    {
        if ($event->getStatus() !== EventStatus::SCHEDULED || $event->getStartsAt() <= new \DateTimeImmutable()) {
            throw new EventNotBookableException("Cet événement n'est pas disponible à la réservation.");
        }

        if (empty($seatIds)) {
            return;
        }

        if (count($seatIds) !== count(array_unique($seatIds)) || min($seatIds) <= 0) {
            throw new DomainException('La liste des sièges contient des identifiants invalides ou dupliqués.');
        }

        $existingSeatCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(\App\Entity\Seat::class, 's')
            ->where('s.id IN (:seatIds)')
            ->setParameter('seatIds', $seatIds)
            ->getQuery()
            ->getSingleScalarResult();

        if ($existingSeatCount !== count($seatIds)) {
            throw new DomainException('Un ou plusieurs sièges demandés n’existent pas.');
        }

        $currentHolds = $this->getUserActiveHolds($event, $user);
        if (count($currentHolds) + count($seatIds) > 6) {
            throw new MaxHoldsExceededException('Limite de 6 places temporaires par client atteinte pour cet événement.');
        }

        $soldTicket = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Ticket::class, 't')
            ->where('t.event = :event')
            ->andWhere('t.seat IN (:seatIds)')
            ->andWhere('t.status = :status')
            ->setParameter('event', $event)
            ->setParameter('seatIds', $seatIds)
            ->setParameter('status', TicketStatus::PAID)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($soldTicket !== null) {
            throw new SeatAlreadySoldException(sprintf(
                'Le siège #%d est déjà vendu pour cet événement.',
                $soldTicket->getSeat()->getId()
            ));
        }

        $acquiredKeys = [];

        try {
            foreach ($seatIds as $seatId) {
                $key = sprintf('hold:event:%d:seat:%d', $event->getId(), $seatId);
                $result = $this->redis->set($key, (string) $user->getId(), 'EX', $ttl, 'NX');
                $isAcquired = ($result !== null && (string) $result === 'OK');

                if ($isAcquired) {
                    $acquiredKeys[] = $key;
                } else {
                    $currentOwner = $this->redis->get($key);
                    if ((string) $currentOwner !== (string) $user->getId()) {
                        foreach ($acquiredKeys as $acquiredKey) {
                            $this->redis->del([$acquiredKey]);
                        }

                        throw new SeatAlreadyHeldException(sprintf(
                            'Le siège #%d est déjà réservé par un autre utilisateur.',
                            $seatId
                        ));
                    }
                }
            }
        } catch (\Throwable $e) {
            if (!$e instanceof SeatAlreadyHeldException) {
                foreach ($acquiredKeys as $acquiredKey) {
                    $this->redis->del([$acquiredKey]);
                }
            }

            throw $e;
        }
    }

    public function releaseSeat(Event $event, int $seatId, User $user): void
    {
        $key = sprintf('hold:event:%d:seat:%d', $event->getId(), $seatId);
        $owner = $this->redis->get($key);

        if ($owner === null) {
            return;
        }

        $isOwner = ((string) $owner === (string) $user->getId());
        $isPrivileged = in_array($user->getRole(), [UserRole::ADMIN, UserRole::CASHIER], true);

        if ($isOwner || $isPrivileged) {
            $this->redis->del([$key]);
        }
    }

    /**
     * @return list<array{seatId: int, ttl: int}>
     */
    public function getUserActiveHolds(Event $event, User $user): array
    {
        $pattern = sprintf('hold:event:%d:seat:*', $event->getId());
        $keys = (array) $this->redis->keys($pattern);
        $activeHolds = [];

        foreach ($keys as $key) {
            $keyStr = (string) $key;
            if (preg_match('/hold:event:\d+:seat:(\d+)$/', $keyStr, $matches)) {
                $seatId = (int) $matches[1];
                $owner = $this->redis->get($keyStr);

                if ((string) $owner === (string) $user->getId()) {
                    $ttl = (int) $this->redis->ttl($keyStr);
                    if ($ttl > 0) {
                        $activeHolds[] = [
                            'seatId' => $seatId,
                            'ttl' => $ttl,
                        ];
                    }
                }
            }
        }

        return $activeHolds;
    }

    /**
     * @return array<int, array{isHeld: bool, isHeldByMe: bool, ttl: int}>
     */
    public function getHeldSeatInfo(Event $event, ?User $currentUser = null): array
    {
        $pattern = sprintf('hold:event:%d:seat:*', $event->getId());
        $keys = (array) $this->redis->keys($pattern);
        $info = [];

        foreach ($keys as $key) {
            $keyStr = (string) $key;
            if (preg_match('/hold:event:\d+:seat:(\d+)$/', $keyStr, $matches)) {
                $seatId = (int) $matches[1];
                $owner = $this->redis->get($keyStr);
                $ttl = (int) $this->redis->ttl($keyStr);

                if ($owner !== null && $ttl > 0) {
                    $isHeldByMe = ($currentUser !== null && (string) $owner === (string) $currentUser->getId());
                    $info[$seatId] = [
                        'isHeld' => true,
                        'isHeldByMe' => $isHeldByMe,
                        'ttl' => $ttl,
                    ];
                }
            }
        }

        return $info;
    }

    /**
     * @param int[] $seatIds
     */
    public function verifyUserHolds(Event $event, array $seatIds, User $user): bool
    {
        if (empty($seatIds)) {
            return false;
        }

        foreach ($seatIds as $seatId) {
            $key = sprintf('hold:event:%d:seat:%d', $event->getId(), $seatId);
            $owner = $this->redis->get($key);

            if ($owner === null || (string) $owner !== (string) $user->getId()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int[] $seatIds
     */
    public function releaseHoldsForPurchase(Event $event, array $seatIds): void
    {
        if (empty($seatIds)) {
            return;
        }

        $keys = array_map(
            static fn(int $seatId): string => sprintf('hold:event:%d:seat:%d', $event->getId(), $seatId),
            $seatIds
        );

        $this->redis->del($keys);
    }

    public function clearAllHoldsForEvent(Event $event): void
    {
        $pattern = sprintf('hold:event:%d:seat:*', $event->getId());
        $keys = (array) $this->redis->keys($pattern);

        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }
}
