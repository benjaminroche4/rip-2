<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contact follow-up history (status/motif changes) + planned next step.
 */
final class Version20260731093442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact_event audit table and contact.next_step';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contact_event (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) DEFAULT NULL, closure_reason VARCHAR(30) DEFAULT NULL, author_name VARCHAR(120) DEFAULT NULL, author_avatar VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, contact_id INT NOT NULL, INDEX IDX_16841AA8E7A1254A (contact_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE contact_event ADD CONSTRAINT FK_16841AA8E7A1254A FOREIGN KEY (contact_id) REFERENCES contact (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact ADD next_step VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_event DROP FOREIGN KEY FK_16841AA8E7A1254A');
        $this->addSql('DROP TABLE contact_event');
        $this->addSql('ALTER TABLE contact DROP next_step');
    }
}
