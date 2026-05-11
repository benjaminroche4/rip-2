<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511115114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create document_request, person_request and person_request_document tables for the bilingual document request flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_request (
            id INT AUTO_INCREMENT NOT NULL,
            typology VARCHAR(50) NOT NULL,
            drive_link VARCHAR(512) NOT NULL,
            language VARCHAR(2) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE person_request (
            id INT AUTO_INCREMENT NOT NULL,
            document_request_id INT NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            position INT NOT NULL,
            INDEX IDX_PERSON_REQUEST_REQUEST (document_request_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE person_request_document (
            person_request_id INT NOT NULL,
            document_id INT NOT NULL,
            INDEX IDX_PRD_PERSON (person_request_id),
            INDEX IDX_PRD_DOCUMENT (document_id),
            PRIMARY KEY (person_request_id, document_id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE person_request ADD CONSTRAINT FK_PERSON_REQUEST_REQUEST FOREIGN KEY (document_request_id) REFERENCES document_request (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE person_request_document ADD CONSTRAINT FK_PRD_PERSON FOREIGN KEY (person_request_id) REFERENCES person_request (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE person_request_document ADD CONSTRAINT FK_PRD_DOCUMENT FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person_request_document DROP FOREIGN KEY FK_PRD_DOCUMENT');
        $this->addSql('ALTER TABLE person_request_document DROP FOREIGN KEY FK_PRD_PERSON');
        $this->addSql('ALTER TABLE person_request DROP FOREIGN KEY FK_PERSON_REQUEST_REQUEST');
        $this->addSql('DROP TABLE person_request_document');
        $this->addSql('DROP TABLE person_request');
        $this->addSql('DROP TABLE document_request');
    }
}
