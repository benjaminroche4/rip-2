<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assignee on contact submissions: which team member follows the lead.
 * Real FK (not a snapshot): it is a living pointer, freed when the user
 * account is deleted.
 */
final class Version20260730221349 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the assigned follow-up person to contact submissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD assigned_to_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contact ADD CONSTRAINT FK_4C62E638F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4C62E638F4BD7827 ON contact (assigned_to_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP FOREIGN KEY FK_4C62E638F4BD7827');
        $this->addSql('DROP INDEX IDX_4C62E638F4BD7827 ON contact');
        $this->addSql('ALTER TABLE contact DROP assigned_to_id');
    }
}
