<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502195940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen contact.phone_number to 25 chars to fit E.164 with optional formatting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact CHANGE phone_number phone_number VARCHAR(25) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact CHANGE phone_number phone_number VARCHAR(20) DEFAULT NULL');
    }
}
