<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427063459 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.avatar_filename to store the locally-cached profile picture (UUID-based, served via AvatarController).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD avatar_filename VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP avatar_filename');
    }
}
