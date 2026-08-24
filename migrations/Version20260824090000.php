<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The lead's guarantor type becomes a multi-select (a household can combine
 * e.g. a physical guarantor and Garantme), stored as a CSV of GuarantorType
 * values like project_furnishing. Existing single values stay valid CSV, so
 * a plain rename + widen keeps the data. Conditional (parallel sessions may
 * collide).
 */
final class Version20260824090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contact project guarantor type becomes a CSV multi-select (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('contact');
        if ($table->hasColumn('project_guarantor_type') && !$table->hasColumn('project_guarantor_types')) {
            $this->addSql('ALTER TABLE contact CHANGE project_guarantor_type project_guarantor_types VARCHAR(40) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact CHANGE project_guarantor_types project_guarantor_type VARCHAR(10) DEFAULT NULL');
    }
}
