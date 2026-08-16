<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agency directory enrichment: team-only note, website and specialties on
 * the agency, plus a deactivation timestamp on both agencies and agents
 * (an inactive row leaves the pickers but keeps its history).
 */
final class Version20260816130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agency note/website/specialties and deactivated_at on agency and real_estate_agent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agency ADD website VARCHAR(255) DEFAULT NULL, ADD specialties JSON DEFAULT NULL, ADD note LONGTEXT DEFAULT NULL, ADD deactivated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE real_estate_agent ADD deactivated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agency DROP website, DROP specialties, DROP note, DROP deactivated_at');
        $this->addSql('ALTER TABLE real_estate_agent DROP deactivated_at');
    }
}
