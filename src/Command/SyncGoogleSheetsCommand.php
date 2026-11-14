<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\OutboxMessage;
use App\Service\GoogleSheetsSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:sync-google-sheets',
    description: 'Synchronize pending sales events with Google Sheets.',
)]
class SyncGoogleSheetsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GoogleSheetsSyncService $syncService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $messages = $this->em->getRepository(OutboxMessage::class)->createQueryBuilder('m')
            ->where('m.processedAt IS NULL')
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        $failed = 0;
        foreach ($messages as $message) {
            try {
                $this->syncService->synchronize($message);
                $message->markProcessed();
                $this->em->flush();
            } catch (\Throwable $exception) {
                ++$failed;
                $message->recordFailure($exception);
                $this->em->flush();
                $this->logger->error('Google Sheets synchronization failed.', [
                    'outboxId' => $message->getId(),
                    'eventType' => $message->getEventType(),
                    'exception' => $exception,
                ]);
            }
        }

        $output->writeln(sprintf(
            'Processed %d message(s), %d failure(s).',
            count($messages) - $failed,
            $failed,
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
