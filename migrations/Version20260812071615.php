<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260812071615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the visit_photo table (property photos stored in the bucket)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE visit_photo (id INT AUTO_INCREMENT NOT NULL, path VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, visit_id INT NOT NULL, INDEX IDX_61027F7975FA0FF2 (visit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE visit_photo ADD CONSTRAINT FK_61027F7975FA0FF2 FOREIGN KEY (visit_id) REFERENCES visit (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE visit_photo DROP FOREIGN KEY FK_61027F7975FA0FF2');
        $this->addSql('DROP TABLE visit_photo');
    }
}
