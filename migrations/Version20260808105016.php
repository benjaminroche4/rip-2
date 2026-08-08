<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808105016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_person ADD current_city VARCHAR(100) DEFAULT NULL, ADD visa_status VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE dossier_search ADD guarantor_status VARCHAR(20) DEFAULT NULL, ADD occupants INT DEFAULT NULL, ADD equipment LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier_person DROP current_city, DROP visa_status');
        $this->addSql('ALTER TABLE dossier_search DROP guarantor_status, DROP occupants, DROP equipment');
    }
}
