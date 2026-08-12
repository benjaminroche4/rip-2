<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811132307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the two-factor authentication columns on user (encrypted TOTP secret, hashed backup codes, trusted-device version)';
    }

    public function up(Schema $schema): void
    {
        // backup_codes goes through nullable + backfill: adding a NOT NULL
        // JSON column on a populated table would leave invalid values on
        // existing rows (same trap as the visit enum columns).
        $this->addSql('ALTER TABLE user ADD totp_secret VARCHAR(255) DEFAULT NULL, ADD totp_enabled_at DATETIME DEFAULT NULL, ADD backup_codes JSON DEFAULT NULL, ADD trusted_token_version INT DEFAULT 0 NOT NULL');
        $this->addSql(<<<'SQL'
                UPDATE user SET backup_codes = '[]' WHERE backup_codes IS NULL
            SQL);
        $this->addSql('ALTER TABLE user MODIFY backup_codes JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP totp_secret, DROP totp_enabled_at, DROP backup_codes, DROP trusted_token_version');
    }
}
