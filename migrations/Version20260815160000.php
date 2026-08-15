<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Temporary lock of a dossier's public deposit space, toggled by the staff
 * from the file module card.
 */
final class Version20260815160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dossier.deposit_locked_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier ADD deposit_locked_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier DROP deposit_locked_at');
    }
}
