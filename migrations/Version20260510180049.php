<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510180049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create document table (slug + bilingual name/description for admin-managed documents)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document (
            id INT AUTO_INCREMENT NOT NULL,
            slug VARCHAR(150) NOT NULL,
            name_fr VARCHAR(255) NOT NULL,
            name_en VARCHAR(255) NOT NULL,
            description_fr LONGTEXT DEFAULT NULL,
            description_en LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_D8698A76989D9B62 (slug),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE document');
    }
}
