<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Event kind discriminator (recap emails in the follow-up thread).
 */
final class Version20260731143406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact_event.kind';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_event ADD kind VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_event DROP kind');
    }
}
