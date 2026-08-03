<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260802191720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dossier_search (Recherche seed) and dossier_note (fil de suivi) tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dossier_note (id INT AUTO_INCREMENT NOT NULL, text LONGTEXT NOT NULL, created_at DATETIME NOT NULL, author_id INT NOT NULL, author_name VARCHAR(120) NOT NULL, author_avatar VARCHAR(255) DEFAULT NULL, dossier_id INT NOT NULL, INDEX IDX_43873B67611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dossier_search (id INT AUTO_INCREMENT NOT NULL, budget INT DEFAULT NULL, areas VARCHAR(255) DEFAULT NULL, move_in_at DATETIME DEFAULT NULL, property_type VARCHAR(255) DEFAULT NULL, stay_duration VARCHAR(50) DEFAULT NULL, furnishing VARCHAR(255) DEFAULT NULL, guarantor_type VARCHAR(50) DEFAULT NULL, note LONGTEXT DEFAULT NULL, dossier_id INT NOT NULL, UNIQUE INDEX UNIQ_5C818F8E611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dossier_note ADD CONSTRAINT FK_43873B67611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dossier_search ADD CONSTRAINT FK_5C818F8E611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_note DROP FOREIGN KEY FK_43873B67611C0C56');
        $this->addSql('ALTER TABLE dossier_search DROP FOREIGN KEY FK_5C818F8E611C0C56');
        $this->addSql('DROP TABLE dossier_note');
        $this->addSql('DROP TABLE dossier_search');
    }
}
