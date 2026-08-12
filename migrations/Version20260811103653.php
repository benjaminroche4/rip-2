<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811103653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE visit ADD booked_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE visit ADD CONSTRAINT FK_437EE939F4A5BD90 FOREIGN KEY (booked_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_437EE939F4A5BD90 ON visit (booked_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE visit DROP FOREIGN KEY FK_437EE939F4A5BD90');
        $this->addSql('DROP INDEX IDX_437EE939F4A5BD90 ON visit');
        $this->addSql('ALTER TABLE visit DROP booked_by_id');
    }
}
