<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GoogleSheetConfiguration;
use App\Entity\OutboxMessage;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\Permission;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\ValueRange;
use Psr\Log\LoggerInterface;

class GoogleSheetsSyncService
{
    private const TAB_NAME = 'Ventes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function synchronize(OutboxMessage $message): void
    {
        $client = $this->createClient();
        $spreadsheetId = $this->getOrCreateSpreadsheet($client);
        $sheets = new Sheets($client);
        $eventId = (string) $message->getId();

        $existingIds = $sheets->spreadsheets_values->get($spreadsheetId, self::TAB_NAME.'!K:K')->getValues() ?? [];
        foreach ($existingIds as $row) {
            if (($row[0] ?? null) === $eventId) {
                return;
            }
        }

        $payload = $message->getPayload();
        $row = [[
            $payload['ticketId'] ?? null,
            $payload['eventId'] ?? null,
            $payload['eventTitle'] ?? null,
            $payload['eventStartsAt'] ?? null,
            $payload['seat'] ?? null,
            $payload['zone'] ?? null,
            $payload['customerEmail'] ?? null,
            isset($payload['amountCents']) ? $payload['amountCents'] / 100 : null,
            $payload['coupon'] ?? null,
            $payload['action'] ?? null,
            $eventId,
            $payload['occurredAt'] ?? null,
        ]];

        $sheets->spreadsheets_values->append(
            $spreadsheetId,
            self::TAB_NAME.'!A:L',
            new ValueRange(['values' => $row]),
            [
                'valueInputOption' => 'RAW',
                'insertDataOption' => 'INSERT_ROWS',
            ],
        );
    }

    private function createClient(): Client
    {
        $credentialsPath = trim((string) ($_ENV['GOOGLE_SERVICE_ACCOUNT_JSON'] ?? getenv('GOOGLE_SERVICE_ACCOUNT_JSON') ?: ''));
        if ($credentialsPath === '' || !is_readable($credentialsPath)) {
            throw new \RuntimeException('GOOGLE_SERVICE_ACCOUNT_JSON must point to a readable service-account JSON file.');
        }

        $client = new Client();
        $client->setApplicationName('Théâtre Royal');
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([
            Sheets::SPREADSHEETS,
            Drive::DRIVE,
        ]);

        return $client;
    }

    private function getOrCreateSpreadsheet(Client $client): string
    {
        $configuration = $this->em->getRepository(GoogleSheetConfiguration::class)->find(1);
        if ($configuration instanceof GoogleSheetConfiguration) {
            return (string) $configuration->getSpreadsheetId();
        }

        $title = trim((string) ($_ENV['GOOGLE_SHEETS_TITLE'] ?? getenv('GOOGLE_SHEETS_TITLE') ?: ''));
        if ($title === '') {
            throw new \RuntimeException('GOOGLE_SHEETS_TITLE must be configured.');
        }

        $sheets = new Sheets($client);
        $spreadsheet = $sheets->spreadsheets->create(
            new Spreadsheet(['properties' => ['title' => $title]]),
            ['fields' => 'spreadsheetId,spreadsheetUrl'],
        );
        $spreadsheetId = (string) $spreadsheet->getSpreadsheetId();

        $sheets->spreadsheets->batchUpdate($spreadsheetId, new BatchUpdateSpreadsheetRequest([
            'requests' => [
                new SheetsRequest([
                    'updateSheetProperties' => [
                        'properties' => [
                            'sheetId' => 0,
                            'title' => self::TAB_NAME,
                        ],
                        'fields' => 'title',
                    ],
                ]),
            ],
        ]));

        $sheets->spreadsheets_values->update(
            $spreadsheetId,
            self::TAB_NAME.'!A1:L1',
            new ValueRange(['values' => [[
                'Ticket ID',
                'Event ID',
                'Event',
                'Event date',
                'Seat',
                'Zone',
                'Customer email',
                'Amount (MAD)',
                'Coupon',
                'Action',
                'Outbox event ID',
                'Occurred at',
            ]]]),
            ['valueInputOption' => 'RAW'],
        );

        $directorEmail = trim((string) ($_ENV['GOOGLE_SHEETS_DIRECTOR_EMAIL'] ?? getenv('GOOGLE_SHEETS_DIRECTOR_EMAIL') ?: ''));
        if ($directorEmail !== '') {
            (new Drive($client))->permissions->create(
                $spreadsheetId,
                new Permission([
                    'type' => 'user',
                    'role' => 'writer',
                    'emailAddress' => $directorEmail,
                ]),
                ['sendNotificationEmail' => false],
            );
        }

        $configuration = new GoogleSheetConfiguration(
            $spreadsheetId,
            (string) $spreadsheet->getSpreadsheetUrl(),
        );
        $this->em->persist($configuration);
        $this->em->flush();

        $this->logger->info('Google Sheet created for sales synchronization.', [
            'spreadsheetId' => $spreadsheetId,
            'spreadsheetUrl' => $spreadsheet->getSpreadsheetUrl(),
        ]);

        return $spreadsheetId;
    }
}
