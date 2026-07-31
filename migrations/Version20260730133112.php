<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tracks who handled a contact submission and when: both fields are set on
 * every status change from the admin. Stored as a display-name snapshot
 * (not a FK) so the history survives account renames/deletions.
 */
final class Version20260730133112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track who changed a contact submission status, and when.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD status_changed_by VARCHAR(120) DEFAULT NULL, ADD status_changed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP status_changed_by, DROP status_changed_at');
    }
}
