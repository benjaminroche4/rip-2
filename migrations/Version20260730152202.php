<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Follow-up notes thread on contact submissions. Author is stored as an id
 * plus display snapshots (name/avatar) rather than a FK to user, so the
 * thread survives account changes; rows die with their contact (CASCADE).
 */
final class Version20260730152202 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contact_note table for the follow-up thread on submissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE contact_note (
                id INT AUTO_INCREMENT NOT NULL,
                contact_id INT NOT NULL,
                text LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                author_id INT NOT NULL,
                author_name VARCHAR(120) NOT NULL,
                author_avatar VARCHAR(255) DEFAULT NULL,
                INDEX IDX_2B231E15E7A1254A (contact_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE contact_note ADD CONSTRAINT FK_2B231E15E7A1254A FOREIGN KEY (contact_id) REFERENCES contact (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE contact_note');
    }
}
