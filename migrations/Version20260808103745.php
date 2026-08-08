<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808103745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dossier_event (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(40) NOT NULL, payload JSON NOT NULL, author_name VARCHAR(120) DEFAULT NULL, author_avatar VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, dossier_id INT NOT NULL, INDEX IDX_F22E10E0611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dossier_event ADD CONSTRAINT FK_F22E10E0611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_event DROP FOREIGN KEY FK_F22E10E0611C0C56');
        $this->addSql('DROP TABLE dossier_event');
    }
}
