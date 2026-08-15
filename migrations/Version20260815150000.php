<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sliding expiry of the deposit pairing code: the code dies 90 days after
 * the last email embedding it, every send re-arms the window. Existing open
 * dossiers are backfilled to "armed now" so the codes already in circulation
 * survive the deployment; closed dossiers keep NULL (their code is refused
 * anyway, and a rotation re-arms it if they ever reopen).
 */
final class Version20260815150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dossier.pairing_code_sent_at (sliding pairing-code expiry) with a backfill on open dossiers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier ADD pairing_code_sent_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE dossier SET pairing_code_sent_at = NOW() WHERE closed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier DROP pairing_code_sent_at');
    }
}
