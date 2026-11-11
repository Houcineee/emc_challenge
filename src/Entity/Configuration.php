<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'configurations')]
#[ORM\UniqueConstraint(name: 'uniq_zone_event_type', columns: ['zone_id', 'event_type_id'])]
class Configuration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Zone::class, inversedBy: 'configurations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Zone $zone = null;

    #[ORM\ManyToOne(targetEntity: EventType::class, inversedBy: 'configurations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EventType $eventType = null;

    #[ORM\Column]
    private ?bool $isSeated = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getZone(): ?Zone
    {
        return $this->zone;
    }

    public function setZone(?Zone $zone): static
    {
        $this->zone = $zone;

        return $this;
    }

    public function getEventType(): ?EventType
    {
        return $this->eventType;
    }

    public function setEventType(?EventType $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    public function isSeated(): ?bool
    {
        return $this->isSeated;
    }

    public function setIsSeated(bool $isSeated): static
    {
        $this->isSeated = $isSeated;

        return $this;
    }
}
