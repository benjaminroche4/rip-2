<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lead quality feedback filled from the admin after a status change:
 * a 1-5 star rating plus a free-text note.
 */
final class Version20260730140704 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lead quality rating and note to contact submissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD lead_rating SMALLINT DEFAULT NULL, ADD lead_note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP lead_rating, DROP lead_note');
    }
}
