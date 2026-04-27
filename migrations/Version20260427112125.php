<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427112125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.language to store the user preferred locale (fr|en) — used to redirect to the right localized URL after login.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD language VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP language');
    }
}
