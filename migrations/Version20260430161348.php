<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430161348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.last_login_at to surface the last successful interactive login in the admin user list.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD last_login_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP last_login_at');
    }
}
