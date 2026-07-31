<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Preferred recontact channel (phone, email, sms, whatsapp, other) picked
 * by the admin while qualifying a lead.
 */
final class Version20260730162648 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the preferred recontact channel to contact submissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD recontact_channel VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP recontact_channel');
    }
}
