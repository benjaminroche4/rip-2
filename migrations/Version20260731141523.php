<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Intake source on contact (form/phone/email/website/whatsapp).
 */
final class Version20260731141523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact.source (default form)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD source VARCHAR(15) DEFAULT \'form\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP source');
    }
}
