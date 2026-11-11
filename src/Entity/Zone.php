<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ZoneName;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'zones')]
class Zone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: ZoneName::class, unique: true)]
    private ?ZoneName $name = null;

    #[ORM\Column]
    private ?int $capacity = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $priceMultiplierPercent = 0;

    /**
     * @var Collection<int, Seat>
     */
    #[ORM\OneToMany(targetEntity: Seat::class, mappedBy: 'zone')]
    private Collection $seats;

    /**
     * @var Collection<int, Configuration>
     */
    #[ORM\OneToMany(targetEntity: Configuration::class, mappedBy: 'zone')]
    private Collection $configurations;

    public function __construct()
    {
        $this->seats = new ArrayCollection();
        $this->configurations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?ZoneName
    {
        return $this->name;
    }

    public function setName(ZoneName $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    public function setCapacity(int $capacity): static
    {
        $this->capacity = $capacity;

        return $this;
    }

    public function getPriceMultiplierPercent(): int
    {
        return $this->priceMultiplierPercent;
    }

    public function setPriceMultiplierPercent(int $priceMultiplierPercent): static
    {
        $this->priceMultiplierPercent = $priceMultiplierPercent;

        return $this;
    }

    /**
     * @return Collection<int, Seat>
     */
    public function getSeats(): Collection
    {
        return $this->seats;
    }

    public function addSeat(Seat $seat): static
    {
        if (!$this->seats->contains($seat)) {
            $this->seats->add($seat);
            $seat->setZone($this);
        }

        return $this;
    }

    public function removeSeat(Seat $seat): static
    {
        $this->seats->removeElement($seat);

        return $this;
    }

    /**
     * @return Collection<int, Configuration>
     */
    public function getConfigurations(): Collection
    {
        return $this->configurations;
    }

    public function addConfiguration(Configuration $configuration): static
    {
        if (!$this->configurations->contains($configuration)) {
            $this->configurations->add($configuration);
            $configuration->setZone($this);
        }

        return $this;
    }

    public function removeConfiguration(Configuration $configuration): static
    {
        $this->configurations->removeElement($configuration);

        return $this;
    }
}
