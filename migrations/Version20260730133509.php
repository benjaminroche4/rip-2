<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Snapshot of the handling admin's avatar filename, alongside the existing
 * name snapshot, so the contact card can show who treated the request with
 * their profile picture.
 */
final class Version20260730133509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Snapshot the avatar of the admin who changed a contact status.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD status_changed_by_avatar VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP status_changed_by_avatar');
    }
}
