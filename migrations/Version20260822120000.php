<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The central agenda event of a visit now lives in the dossier manager's
 * (the closer's) own Workspace agenda when they have one: store that owner
 * email so every later PATCH/DELETE impersonates the right agenda, even
 * after a manager change (the event never moves). NULL keeps the legacy
 * behaviour: the event lives in the default central agenda (contact@).
 * Conditional (parallel sessions may collide).
 */
final class Version20260822120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.calendar_central_owner (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if (!$table->hasColumn('calendar_central_owner')) {
            $this->addSql('ALTER TABLE visit ADD calendar_central_owner VARCHAR(180) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE visit DROP calendar_central_owner');
    }
}
