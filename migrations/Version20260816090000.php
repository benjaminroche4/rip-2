<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Team-only free note on a real-estate agent (directory entry), shown as a
 * discreet tooltip in the back-office agents list.
 */
final class Version20260816090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add real_estate_agent.note (team-only free note)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent ADD note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent DROP note');
    }
}
