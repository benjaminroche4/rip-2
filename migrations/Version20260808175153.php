<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808175153 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace ROLE_EDITOR with the per-section ROLE_SECTION_TOOLS in user roles';
    }

    public function up(Schema $schema): void
    {
        // Editors only had the Outils section: the new granular role keeps
        // exactly that access. ROLE_STAFF is implied via the role hierarchy.
        $this->addSql(<<<'SQL'
                UPDATE user SET roles = REPLACE(roles, 'ROLE_EDITOR', 'ROLE_SECTION_TOOLS')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                UPDATE user SET roles = REPLACE(roles, 'ROLE_SECTION_TOOLS', 'ROLE_EDITOR')
            SQL);
    }
}
