<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Offer ("formule") stored on the dossier itself: copied from the source
 * contact at conversion, picked by hand on from-scratch dossiers. Existing
 * converted dossiers are backfilled from their contact.
 */
final class Version20260815180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dossier.offer, backfilled from the source contact';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier ADD offer VARCHAR(20) DEFAULT NULL');
        $this->addSql('UPDATE dossier d JOIN contact c ON c.reference = d.source_contact_reference COLLATE utf8mb4_unicode_ci SET d.offer = c.offer WHERE c.offer IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier DROP offer');
    }
}
