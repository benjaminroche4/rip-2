<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Manual step validation on the dossier path: each step card carries a
 * "Valider" button, the validated steps are stored on the dossier itself.
 */
final class Version20260815090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dossier.validated_steps (manual step validation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier ADD validated_steps JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier DROP validated_steps');
    }
}
