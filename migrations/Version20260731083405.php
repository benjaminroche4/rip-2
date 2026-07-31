<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The "went to a competitor" closure reason becomes "profile not a fit for
 * our services". Data migration only: the enum case value changed, and an
 * unknown stored value would break hydration.
 */
final class Version20260731083405 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the competitor closure reason to profile_mismatch.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE contact SET closure_reason = 'profile_mismatch' WHERE closure_reason = 'competitor'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE contact SET closure_reason = 'competitor' WHERE closure_reason = 'profile_mismatch'");
    }
}
