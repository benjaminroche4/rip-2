<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visit: last-editor snapshot (name + avatar + date), same pattern as the
 * agent/agency audit columns. Conditional guards: parallel sessions.
 */
final class Version20260818120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.updated_at + updated_by snapshots';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if (!$table->hasColumn('updated_at')) {
            $this->addSql('ALTER TABLE visit ADD updated_at DATETIME DEFAULT NULL');
        }
        if (!$table->hasColumn('updated_by_name')) {
            $this->addSql('ALTER TABLE visit ADD updated_by_name VARCHAR(100) DEFAULT NULL');
        }
        if (!$table->hasColumn('updated_by_avatar')) {
            $this->addSql('ALTER TABLE visit ADD updated_by_avatar VARCHAR(255) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        foreach (['updated_at', 'updated_by_name', 'updated_by_avatar'] as $column) {
            if ($table->hasColumn($column)) {
                $this->addSql('ALTER TABLE visit DROP '.$column);
            }
        }
    }
}
