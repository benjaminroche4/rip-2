<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260812064543 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reduce visit types to visit/inventory/technical-intervention and cap durations at 60 minutes';
    }

    public function up(Schema $schema): void
    {
        // Removed enum cases fold into the generic visit; 1h30 folds into
        // the new "+1h" ceiling.
        $this->addSql(<<<'SQL'
                UPDATE visit SET type = 'property_visit' WHERE type IN ('counter_visit', 'key_handover')
            SQL);
        $this->addSql(<<<'SQL'
                UPDATE visit SET duration_minutes = 60 WHERE duration_minutes > 60
            SQL);
    }

    public function down(Schema $schema): void
    {
        // The folded values are indistinguishable afterwards: nothing to restore.
    }
}
