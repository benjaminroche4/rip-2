<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visit: the client-present flag now defaults to false (unticked). Column
 * default only; existing rows keep their stored value.
 */
final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'visit.client_present defaults to false';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE visit CHANGE client_present client_present TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE visit CHANGE client_present client_present TINYINT(1) DEFAULT 1 NOT NULL');
    }
}
