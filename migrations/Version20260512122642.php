<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512122642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add category column to document (identity|work|housing|financial|education|other)';
    }

    public function up(Schema $schema): void
    {
        // Default to "other" for existing rows so the NOT NULL constraint
        // doesn't blow up; new rows are then forced through the form which
        // requires an explicit choice via Assert\NotNull.
        $this->addSql("ALTER TABLE document ADD category VARCHAR(30) NOT NULL DEFAULT 'other'");
        $this->addSql('ALTER TABLE document ALTER category DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP category');
    }
}
