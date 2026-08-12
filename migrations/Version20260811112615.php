<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811112615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill the visit enum columns (type/mode/status) left empty on pre-existing rows';
    }

    public function up(Schema $schema): void
    {
        // The columns were added NOT NULL on a populated table: MySQL filled
        // the existing rows with '', which the PHP backed enums reject at
        // hydration. Repair with the entity defaults.
        $this->addSql("UPDATE visit SET type = 'property_visit' WHERE type = ''");
        $this->addSql("UPDATE visit SET mode = 'in_person' WHERE mode = ''");
        $this->addSql("UPDATE visit SET status = 'planned' WHERE status = ''");
    }

    public function down(Schema $schema): void
    {
        // The empty strings were corrupt data, nothing to restore.
    }
}
