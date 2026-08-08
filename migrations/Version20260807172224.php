<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260807172224 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dossier person professional pane: employer, job title, contract dates, trial period, "no profession" flag';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_person ADD no_profession TINYINT DEFAULT 0 NOT NULL, ADD employer VARCHAR(100) DEFAULT NULL, ADD job_title VARCHAR(100) DEFAULT NULL, ADD contract_start_date DATE DEFAULT NULL, ADD contract_end_date DATE DEFAULT NULL, ADD trial_period_over TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_person DROP no_profession, DROP employer, DROP job_title, DROP contract_start_date, DROP contract_end_date, DROP trial_period_over');
    }
}
