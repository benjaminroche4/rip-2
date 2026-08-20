<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-select guarantor types on the dossier search: new JSON column
 * `guarantor_types`, seeded from the legacy single `guarantor_type` (the
 * old value becomes a one-element array). The legacy column stays as a
 * read fallback and keeps mirroring the first selected type. Guarded so
 * environments where the column already exists migrate cleanly.
 */
final class Version20260820090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dossier_search.guarantor_types (JSON, multi-select), seeded from guarantor_type';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('dossier_search')->hasColumn('guarantor_types')) {
            $this->addSql('ALTER TABLE dossier_search ADD guarantor_types JSON DEFAULT NULL');
            $this->addSql("UPDATE dossier_search SET guarantor_types = JSON_ARRAY(guarantor_type) WHERE guarantor_type IS NOT NULL AND guarantor_type != ''");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_search DROP guarantor_types');
    }
}
