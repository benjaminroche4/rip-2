<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Threaded notes on a dossier: a note can now answer another one (depth 1),
 * same model as contact_note. Guarded with hasColumn so environments where
 * the column was added by hand migrate cleanly.
 */
final class Version20260824110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add parent_note_id on dossier_note (threaded replies)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('dossier_note')->hasColumn('parent_note_id')) {
            $this->addSql('ALTER TABLE dossier_note ADD parent_note_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE dossier_note ADD CONSTRAINT FK_DOSSIER_NOTE_PARENT FOREIGN KEY (parent_note_id) REFERENCES dossier_note (id) ON DELETE CASCADE');
            $this->addSql('CREATE INDEX IDX_DOSSIER_NOTE_PARENT ON dossier_note (parent_note_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_note DROP FOREIGN KEY FK_DOSSIER_NOTE_PARENT');
        $this->addSql('DROP INDEX IDX_DOSSIER_NOTE_PARENT ON dossier_note');
        $this->addSql('ALTER TABLE dossier_note DROP parent_note_id');
    }
}
