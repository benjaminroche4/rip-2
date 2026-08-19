<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catch-up migration: the audit columns of the agent/agency cards (who
 * created and last edited the fiche, with their avatar) were mapped on the
 * entities without their migration. Guarded column by column so the
 * environments where they already exist (added by hand) migrate cleanly.
 */
final class Version20260819190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit columns on real_estate_agent and agency';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'real_estate_agent' => ['updated_at' => 'DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'', 'created_by_name' => 'VARCHAR(100) DEFAULT NULL', 'created_by_avatar' => 'VARCHAR(255) DEFAULT NULL', 'updated_by_name' => 'VARCHAR(100) DEFAULT NULL', 'updated_by_avatar' => 'VARCHAR(255) DEFAULT NULL'],
            'agency' => ['updated_at' => 'DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'', 'created_by_name' => 'VARCHAR(100) DEFAULT NULL', 'created_by_avatar' => 'VARCHAR(255) DEFAULT NULL', 'updated_by_name' => 'VARCHAR(100) DEFAULT NULL', 'updated_by_avatar' => 'VARCHAR(255) DEFAULT NULL'],
        ] as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (!$schema->getTable($table)->hasColumn($column)) {
                    $this->addSql(sprintf('ALTER TABLE %s ADD %s %s', $table, $column, $definition));
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent DROP updated_at, DROP created_by_name, DROP created_by_avatar, DROP updated_by_name, DROP updated_by_avatar');
        $this->addSql('ALTER TABLE agency DROP updated_at, DROP created_by_name, DROP created_by_avatar, DROP updated_by_name, DROP updated_by_avatar');
    }
}
