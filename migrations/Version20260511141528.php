<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511141528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pinned flag to document so admins can keep priority items at the top of the catalogue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD pinned TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP pinned');
    }
}
