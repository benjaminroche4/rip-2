<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Business qualification fields on contact submissions, all filled by the
 * admin: planned recall date, closure reason, lead source, and the
 * structured housing project (budget, target areas, move-in date,
 * property type).
 */
final class Version20260730214852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recall date, closure reason, lead source and project fields to contact submissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE contact
                ADD recall_at DATETIME DEFAULT NULL,
                ADD closure_reason VARCHAR(30) DEFAULT NULL,
                ADD lead_source VARCHAR(30) DEFAULT NULL,
                ADD project_budget INT DEFAULT NULL,
                ADD project_areas VARCHAR(150) DEFAULT NULL,
                ADD project_move_in_at DATE DEFAULT NULL,
                ADD project_property_type VARCHAR(100) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP recall_at, DROP closure_reason, DROP lead_source, DROP project_budget, DROP project_areas, DROP project_move_in_at, DROP project_property_type');
    }
}
