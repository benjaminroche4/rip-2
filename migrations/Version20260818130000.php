<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * VisitPhoto: before/after phase of the shot relative to the visit.
 * Existing rows default to 'before' (they were uploaded with the booking or
 * before the split existed). Conditional guard: parallel sessions.
 */
final class Version20260818130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add visit_photo.phase ('before'|'after', default 'before')";
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit_photo');
        if (!$table->hasColumn('phase')) {
            $this->addSql("ALTER TABLE visit_photo ADD phase VARCHAR(10) DEFAULT 'before' NOT NULL");
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('visit_photo');
        if ($table->hasColumn('phase')) {
            $this->addSql('ALTER TABLE visit_photo DROP phase');
        }
    }
}
