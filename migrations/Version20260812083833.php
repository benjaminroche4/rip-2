<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260812083833 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Shared Drive folder/permission ids to dossier and dossier_person for Drive document storage.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier ADD drive_folder_id VARCHAR(64) DEFAULT NULL, ADD drive_manager_permission_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE dossier_person ADD drive_folder_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier DROP drive_folder_id, DROP drive_manager_permission_id');
        $this->addSql('ALTER TABLE dossier_person DROP drive_folder_id');
    }
}
