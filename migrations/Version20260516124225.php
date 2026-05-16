<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extracts the in-flight email-verification challenge from the user table into
 * its own row. Mirrors the existing reset_password_request layout: one active
 * request per user, hashed code, expiration, attempt counter for throttling.
 */
final class Version20260516124225 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move the pending OTP email verification out of the user table into a dedicated email_verification_request table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE email_verification_request (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                attempts INT NOT NULL,
                last_attempt_at DATETIME DEFAULT NULL,
                UNIQUE INDEX UNIQ_EE9588C8A76ED395 (user_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE email_verification_request
                ADD CONSTRAINT FK_EE9588C8A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
        SQL);

        $this->addSql('ALTER TABLE user DROP verification_code, DROP verification_code_expires_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD verification_code VARCHAR(255) DEFAULT NULL, ADD verification_code_expires_at DATETIME DEFAULT NULL');

        $this->addSql('ALTER TABLE email_verification_request DROP FOREIGN KEY FK_EE9588C8A76ED395');
        $this->addSql('DROP TABLE email_verification_request');
    }
}
