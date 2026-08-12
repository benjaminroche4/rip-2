<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811111840 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE visit ADD type VARCHAR(20) NOT NULL, ADD mode VARCHAR(20) NOT NULL, ADD status VARCHAR(20) NOT NULL, ADD listing_url VARCHAR(500) DEFAULT NULL, ADD duration_minutes INT DEFAULT 30 NOT NULL, ADD client_present TINYINT DEFAULT 1 NOT NULL, ADD report LONGTEXT DEFAULT NULL, ADD client_feeling VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE visit DROP type, DROP mode, DROP status, DROP listing_url, DROP duration_minutes, DROP client_present, DROP report, DROP client_feeling');
    }
}
