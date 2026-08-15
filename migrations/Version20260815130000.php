<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step-based dossier statuses: the status now names the step currently
 * pending (persons / search / file / visit / finalization) instead of the
 * old manual pipeline (new / searching / property_found). Existing rows are
 * remapped on their closest equivalent; "awaiting_documents" was derived
 * and never stored.
 */
final class Version20260815130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remap dossier statuses on the pending-step model';
    }

    public function up(Schema $schema): void
    {
        // new = nothing validated yet -> persons step pending. searching =
        // search step validated -> file step pending. property_found =
        // visit done -> finalization.
        $this->addSql("UPDATE dossier SET status = 'persons' WHERE status = 'new'");
        $this->addSql("UPDATE dossier SET status = 'file' WHERE status IN ('searching', 'awaiting_documents')");
        $this->addSql("UPDATE dossier SET status = 'finalization' WHERE status = 'property_found'");
        $this->addSql("ALTER TABLE dossier CHANGE status status VARCHAR(20) DEFAULT 'persons' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE dossier SET status = 'new' WHERE status IN ('persons', 'search')");
        $this->addSql("UPDATE dossier SET status = 'searching' WHERE status IN ('file', 'visit')");
        $this->addSql("UPDATE dossier SET status = 'property_found' WHERE status = 'finalization'");
        $this->addSql("ALTER TABLE dossier CHANGE status status VARCHAR(20) DEFAULT 'new' NOT NULL");
    }
}
