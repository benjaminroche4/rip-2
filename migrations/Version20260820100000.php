<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Threaded internal notes on a lead: a note can now answer another one
 * (depth 1). Guarded with hasColumn so environments where the column was
 * added by hand migrate cleanly.
 */
final class Version20260820100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add parent_note_id on contact_note (threaded replies)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('contact_note')->hasColumn('parent_note_id')) {
            $this->addSql('ALTER TABLE contact_note ADD parent_note_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE contact_note ADD CONSTRAINT FK_CONTACT_NOTE_PARENT FOREIGN KEY (parent_note_id) REFERENCES contact_note (id) ON DELETE CASCADE');
            $this->addSql('CREATE INDEX IDX_CONTACT_NOTE_PARENT ON contact_note (parent_note_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_note DROP FOREIGN KEY FK_CONTACT_NOTE_PARENT');
        $this->addSql('DROP INDEX IDX_CONTACT_NOTE_PARENT ON contact_note');
        $this->addSql('ALTER TABLE contact_note DROP parent_note_id');
    }
}
