<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visit client note translation: client_note_en holds the English
 * translation generated at send time for English-speaking recipients
 * (traceability only, never displayed; overwritten on every send since the
 * French note may have been edited in between). Conditional guards:
 * parallel sessions.
 */
final class Version20260819183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.client_note_en';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if (!$table->hasColumn('client_note_en')) {
            $this->addSql('ALTER TABLE visit ADD client_note_en LONGTEXT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if ($table->hasColumn('client_note_en')) {
            $this->addSql('ALTER TABLE visit DROP client_note_en');
        }
    }
}
