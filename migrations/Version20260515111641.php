<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515111641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add verification_code (argon2id hash of the 6-digit OTP) and verification_code_expires_at columns to user, supporting the OTP email-confirmation flow that replaces the signed-link bundle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD verification_code VARCHAR(255) DEFAULT NULL, ADD verification_code_expires_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP verification_code, DROP verification_code_expires_at');
    }
}
