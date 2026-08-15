<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Richer agent/agency directory: agency brands ("enseignes") as their own
 * table, agency address and contact details, agent specialties (rental /
 * sale) and position inside the agency.
 */
final class Version20260815120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agency_brand table, agency contact columns, agent specialties and position';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agency_brand (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_129E76765E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE agency ADD brand_id INT DEFAULT NULL, ADD address VARCHAR(255) DEFAULT NULL, ADD phone VARCHAR(30) DEFAULT NULL, ADD email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE agency ADD CONSTRAINT FK_70C0C6E644F5D008 FOREIGN KEY (brand_id) REFERENCES agency_brand (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_70C0C6E644F5D008 ON agency (brand_id)');
        $this->addSql('ALTER TABLE real_estate_agent ADD specialties JSON DEFAULT NULL, ADD position VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent DROP specialties, DROP position');
        $this->addSql('ALTER TABLE agency DROP FOREIGN KEY FK_70C0C6E644F5D008');
        $this->addSql('DROP INDEX IDX_70C0C6E644F5D008 ON agency');
        $this->addSql('ALTER TABLE agency DROP brand_id, DROP address, DROP phone, DROP email');
        $this->addSql('DROP TABLE agency_brand');
    }
}
