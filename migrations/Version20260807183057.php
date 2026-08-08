<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260807183057 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dossier documents: per-person requested pieces with status';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dossier_document (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, requested_at DATETIME NOT NULL, received_at DATETIME DEFAULT NULL, person_id INT NOT NULL, INDEX IDX_F0296801217BBB47 (person_id), UNIQUE INDEX uniq_person_document_type (person_id, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dossier_document ADD CONSTRAINT FK_F0296801217BBB47 FOREIGN KEY (person_id) REFERENCES dossier_person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_document DROP FOREIGN KEY FK_F0296801217BBB47');
        $this->addSql('DROP TABLE dossier_document');
    }
}
