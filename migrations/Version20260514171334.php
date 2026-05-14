<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514171334 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill is_profile_complete=1 for users who already have phone_number and nationality set, so existing accounts are not blocked by the profile-completion gate introduced for the Google sign-in flow.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE user SET is_profile_complete = 1 WHERE phone_number IS NOT NULL AND nationality IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE user SET is_profile_complete = 0 WHERE phone_number IS NOT NULL AND nationality IS NOT NULL');
    }
}
