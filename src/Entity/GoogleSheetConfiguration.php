<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'google_sheet_configurations')]
class GoogleSheetConfiguration
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column(length: 255)]
    private ?string $spreadsheetId = null;

    #[ORM\Column(length: 255)]
    private ?string $spreadsheetUrl = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct(string $spreadsheetId, string $spreadsheetUrl)
    {
        $this->spreadsheetId = $spreadsheetId;
        $this->spreadsheetUrl = $spreadsheetUrl;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getSpreadsheetId(): ?string
    {
        return $this->spreadsheetId;
    }

    public function getSpreadsheetUrl(): ?string
    {
        return $this->spreadsheetUrl;
    }
}
