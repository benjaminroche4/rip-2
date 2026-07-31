<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Furnishing preference + guarantor type on the housing project.
 */
final class Version20260731114807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact.project_furnishing and contact.project_guarantor_type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD project_furnishing VARCHAR(15) DEFAULT NULL, ADD project_guarantor_type VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP project_furnishing, DROP project_guarantor_type');
    }
}
