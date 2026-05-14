<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514132631 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nationality column (ISO 3166-1 alpha-2) to user table for the registration flow.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD nationality VARCHAR(2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP nationality');
    }
}
