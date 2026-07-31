<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Intended length of stay on the housing project.
 */
final class Version20260731103617 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact.project_stay_duration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD project_stay_duration VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP project_stay_duration');
    }
}
