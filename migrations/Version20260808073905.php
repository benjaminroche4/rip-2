<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808073905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dossier_document_file (id INT AUTO_INCREMENT NOT NULL, stored_name VARCHAR(64) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, uploaded_at DATETIME NOT NULL, document_id INT NOT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_31682C85C33F7837 (document_id), INDEX IDX_31682C85A2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dossier_document_file ADD CONSTRAINT FK_31682C85C33F7837 FOREIGN KEY (document_id) REFERENCES dossier_document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dossier_document_file ADD CONSTRAINT FK_31682C85A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES dossier_person (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_document_file DROP FOREIGN KEY FK_31682C85C33F7837');
        $this->addSql('ALTER TABLE dossier_document_file DROP FOREIGN KEY FK_31682C85A2B28FE8');
        $this->addSql('DROP TABLE dossier_document_file');
    }
}
