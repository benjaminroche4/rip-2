<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the admin-managed lifecycle status to contact submissions. Existing
 * rows land on 'new' (the entity default) so the badge/filters treat them
 * as untreated until someone triages them.
 */
final class Version20260730130037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lifecycle status to contact submissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE contact ADD status VARCHAR(20) NOT NULL DEFAULT 'new'");
        $this->addSql('ALTER TABLE contact ALTER status DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP status');
    }
}
