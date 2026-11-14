<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261114014751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE google_sheet_configurations (id INT NOT NULL, spreadsheet_id VARCHAR(255) NOT NULL, spreadsheet_url VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE outbox_messages (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(80) NOT NULL, aggregate_id INT NOT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, INDEX idx_outbox_processed_at (processed_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE google_sheet_configurations');
        $this->addSql('DROP TABLE outbox_messages');
    }
}
