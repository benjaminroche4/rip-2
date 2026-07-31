<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Free-form note on the housing project.
 */
final class Version20260731115631 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact.project_note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD project_note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP project_note');
    }
}
