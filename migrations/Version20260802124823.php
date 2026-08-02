<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Furnishing becomes a CSV multi-select (both values possible).
 */
final class Version20260802124823 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen contact.project_furnishing for multi-select CSV';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact CHANGE project_furnishing project_furnishing VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact CHANGE project_furnishing project_furnishing VARCHAR(15) DEFAULT NULL');
    }
}
