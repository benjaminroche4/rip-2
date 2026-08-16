<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Team-wide favorite flag on real-estate agents: powers the "Favoris" tab
 * of the back-office directory and the heart toggles on the cards.
 */
final class Version20260816170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add real_estate_agent.favorited_at (team-wide favorite)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent ADD favorited_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent DROP favorited_at');
    }
}
