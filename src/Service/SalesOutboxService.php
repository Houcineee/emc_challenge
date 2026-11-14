<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;

class SalesOutboxService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function recordTicketSold(Ticket $ticket): void
    {
        $this->recordTicketEvent($ticket, 'TICKET_SOLD', 'SOLD');
    }

    public function recordTicketRefunded(Ticket $ticket): void
    {
        $this->recordTicketEvent($ticket, 'TICKET_REFUNDED', 'REFUNDED');
    }

    private function recordTicketEvent(Ticket $ticket, string $eventType, string $action): void
    {
        $event = $ticket->getEvent();
        $seat = $ticket->getSeat();
        $zone = $seat?->getZone();
        $user = $ticket->getUser();

        $message = new OutboxMessage();
        $message->setEventType($eventType);
        $message->setAggregateId((int) $ticket->getId());
        $message->setPayload([
            'ticketId' => $ticket->getId(),
            'eventId' => $event?->getId(),
            'eventTitle' => $event?->getTitle(),
            'eventStartsAt' => $event?->getStartsAt()?->format(\DateTimeInterface::ATOM),
            'seat' => $seat !== null ? sprintf('%s-%d', $seat->getRowLabel(), $seat->getSeatNumber()) : null,
            'zone' => $zone?->getName()?->value,
            'customerEmail' => $user?->getEmail(),
            'amountCents' => $ticket->getFinalPriceCents(),
            'coupon' => $ticket->getCoupon()?->getCode(),
            'action' => $action,
            'occurredAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        $this->em->persist($message);
    }
}
