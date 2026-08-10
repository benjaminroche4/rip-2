<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809093618 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Promote the agent agency free-text into an agency table (several agents per agency, null = independent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agency (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_70C0C6E65E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE real_estate_agent ADD agency_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE real_estate_agent ADD CONSTRAINT FK_C6062FB6CDEADB2A FOREIGN KEY (agency_id) REFERENCES agency (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C6062FB6CDEADB2A ON real_estate_agent (agency_id)');

        // Data migration: one agency per distinct free-text value (trimmed,
        // case-insensitive via MySQL's default CI collation), then relink.
        $this->addSql(<<<'SQL'
                INSERT INTO agency (name, created_at)
                SELECT DISTINCT TRIM(agency), NOW()
                FROM real_estate_agent
                WHERE agency IS NOT NULL AND TRIM(agency) <> ''
            SQL);
        $this->addSql(<<<'SQL'
                UPDATE real_estate_agent a
                INNER JOIN agency ag ON ag.name = TRIM(a.agency)
                SET a.agency_id = ag.id
            SQL);

        $this->addSql('ALTER TABLE real_estate_agent DROP agency');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent ADD agency VARCHAR(100) DEFAULT NULL');
        $this->addSql(<<<'SQL'
                UPDATE real_estate_agent a
                INNER JOIN agency ag ON ag.id = a.agency_id
                SET a.agency = ag.name
            SQL);
        $this->addSql('ALTER TABLE real_estate_agent DROP FOREIGN KEY FK_C6062FB6CDEADB2A');
        $this->addSql('DROP INDEX IDX_C6062FB6CDEADB2A ON real_estate_agent');
        $this->addSql('ALTER TABLE real_estate_agent DROP agency_id');
        $this->addSql('DROP TABLE agency');
    }
}
