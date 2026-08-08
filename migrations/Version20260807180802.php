<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260807180802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dossier search: replace outdoor spaces with the top floor constraint';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_search ADD top_floor VARCHAR(10) DEFAULT NULL, DROP outdoor_spaces');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_search ADD outdoor_spaces VARCHAR(255) DEFAULT NULL, DROP top_floor');
    }
}
