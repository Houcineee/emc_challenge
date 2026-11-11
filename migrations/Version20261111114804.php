<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111114804 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE configurations (id INT AUTO_INCREMENT NOT NULL, is_seated TINYINT NOT NULL, zone_id INT NOT NULL, event_type_id INT NOT NULL, UNIQUE INDEX uniq_zone_event_type (zone_id, event_type_id), INDEX IDX_31C6AD99F2C3FAB (zone_id), INDEX IDX_31C6AD9401B253C (event_type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE coupons (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, discount_percent INT NOT NULL, max_uses INT NOT NULL, valid_from DATETIME NOT NULL, valid_until DATETIME NOT NULL, event_id INT NOT NULL, UNIQUE INDEX uniq_event_code (event_id, code), INDEX IDX_F564111871F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE event_types (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, UNIQUE INDEX UNIQ_182B381C5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE events (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, base_price_cents INT NOT NULL, starts_at DATETIME NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, event_type_id INT NOT NULL, INDEX IDX_5387574A401B253C (event_type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seats (id INT AUTO_INCREMENT NOT NULL, row_label VARCHAR(10) NOT NULL, seat_number INT NOT NULL, zone_id INT NOT NULL, UNIQUE INDEX uniq_zone_row_seat (zone_id, row_label, seat_number), INDEX IDX_BFE257509F2C3FAB (zone_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tickets (id INT AUTO_INCREMENT NOT NULL, final_price_cents INT NOT NULL, status VARCHAR(255) NOT NULL, paid_at DATETIME NOT NULL, event_id INT NOT NULL, seat_id INT NOT NULL, user_id INT NOT NULL, coupon_id INT DEFAULT NULL, UNIQUE INDEX uniq_event_seat (event_id, seat_id), INDEX IDX_54469DF471F7E88B (event_id), INDEX IDX_54469DF4C1DAFE35 (seat_id), INDEX IDX_54469DF4A76ED395 (user_id), INDEX IDX_54469DF466C5951B (coupon_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE zones (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, capacity INT NOT NULL, price_multiplier_percent INT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_85CAB1685E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE configurations ADD CONSTRAINT FK_31C6AD99F2C3FAB FOREIGN KEY (zone_id) REFERENCES zones (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE configurations ADD CONSTRAINT FK_31C6AD9401B253C FOREIGN KEY (event_type_id) REFERENCES event_types (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coupons ADD CONSTRAINT FK_F564111871F7E88B FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE events ADD CONSTRAINT FK_5387574A401B253C FOREIGN KEY (event_type_id) REFERENCES event_types (id)');
        $this->addSql('ALTER TABLE seats ADD CONSTRAINT FK_BFE257509F2C3FAB FOREIGN KEY (zone_id) REFERENCES zones (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF471F7E88B FOREIGN KEY (event_id) REFERENCES events (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4C1DAFE35 FOREIGN KEY (seat_id) REFERENCES seats (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF466C5951B FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE configurations DROP FOREIGN KEY FK_31C6AD99F2C3FAB');
        $this->addSql('ALTER TABLE configurations DROP FOREIGN KEY FK_31C6AD9401B253C');
        $this->addSql('ALTER TABLE coupons DROP FOREIGN KEY FK_F564111871F7E88B');
        $this->addSql('ALTER TABLE events DROP FOREIGN KEY FK_5387574A401B253C');
        $this->addSql('ALTER TABLE seats DROP FOREIGN KEY FK_BFE257509F2C3FAB');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF471F7E88B');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF4C1DAFE35');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF4A76ED395');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF466C5951B');
        $this->addSql('DROP TABLE configurations');
        $this->addSql('DROP TABLE coupons');
        $this->addSql('DROP TABLE event_types');
        $this->addSql('DROP TABLE events');
        $this->addSql('DROP TABLE seats');
        $this->addSql('DROP TABLE tickets');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE zones');
    }
}
