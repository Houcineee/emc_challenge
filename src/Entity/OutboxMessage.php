<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'outbox_messages')]
#[ORM\Index(name: 'idx_outbox_processed_at', columns: ['processed_at'])]
class OutboxMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private ?string $eventType = null;

    #[ORM\Column]
    private ?int $aggregateId = null;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventType(): ?string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    public function getAggregateId(): ?int
    {
        return $this->aggregateId;
    }

    public function setAggregateId(int $aggregateId): static
    {
        $this->aggregateId = $aggregateId;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function markProcessed(): static
    {
        $this->processedAt = new \DateTimeImmutable();
        $this->lastError = null;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function recordFailure(\Throwable $exception): static
    {
        $this->lastError = mb_substr($exception->getMessage(), 0, 65535);

        return $this;
    }
}
