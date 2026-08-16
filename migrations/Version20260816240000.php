<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aligne les noms des index uniques des références agents/agences sur les
 * noms générés par Doctrine, pour un diff de schéma silencieux.
 */
final class Version20260816240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename agent/agency reference unique indexes to the Doctrine-generated names';
    }

    public function up(Schema $schema): void
    {
        if ($schema->getTable('real_estate_agent')->hasIndex('UNIQ_AGENT_REFERENCE')) {
            $this->addSql('ALTER TABLE real_estate_agent RENAME INDEX UNIQ_AGENT_REFERENCE TO UNIQ_C6062FB6AEA34913');
        }
        if ($schema->getTable('agency')->hasIndex('UNIQ_AGENCY_REFERENCE')) {
            $this->addSql('ALTER TABLE agency RENAME INDEX UNIQ_AGENCY_REFERENCE TO UNIQ_70C0C6E6AEA34913');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent RENAME INDEX UNIQ_C6062FB6AEA34913 TO UNIQ_AGENT_REFERENCE');
        $this->addSql('ALTER TABLE agency RENAME INDEX UNIQ_70C0C6E6AEA34913 TO UNIQ_AGENCY_REFERENCE');
    }
}
