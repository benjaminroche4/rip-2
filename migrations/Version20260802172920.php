<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260802172920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create dossier + dossier_person tables (Dossier bounded context)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dossier (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dossier_person (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, first_name VARCHAR(50) NOT NULL, last_name VARCHAR(50) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(30) DEFAULT NULL, language VARCHAR(2) NOT NULL, primary_contact TINYINT DEFAULT 0 NOT NULL, position INT NOT NULL, dossier_id INT NOT NULL, INDEX IDX_DCAD855F611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dossier_person ADD CONSTRAINT FK_DCAD855F611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_person DROP FOREIGN KEY FK_DCAD855F611C0C56');
        $this->addSql('DROP TABLE dossier');
        $this->addSql('DROP TABLE dossier_person');
    }
}
