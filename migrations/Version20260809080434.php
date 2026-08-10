<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809080434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the real_estate_agent table (back-office agents directory)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE real_estate_agent (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(50) NOT NULL, last_name VARCHAR(50) NOT NULL, agency VARCHAR(100) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE real_estate_agent');
    }
}
