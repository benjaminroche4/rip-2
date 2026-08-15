<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agency logo and agent photo, stored in the private avatar bucket
 * (agencies/<id>/logo, agents/<id>/avatar), served by app_avatar.
 */
final class Version20260815170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agency.logo_filename and real_estate_agent.avatar_filename';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agency ADD logo_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE real_estate_agent ADD avatar_filename VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agency DROP logo_filename');
        $this->addSql('ALTER TABLE real_estate_agent DROP avatar_filename');
    }
}
