<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260802185651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dossier.manager_id (responsable de dossier, nullable, SET NULL on user deletion)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier ADD manager_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dossier ADD CONSTRAINT FK_3D48E037783E3463 FOREIGN KEY (manager_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_3D48E037783E3463 ON dossier (manager_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dossier DROP FOREIGN KEY FK_3D48E037783E3463');
        $this->addSql('DROP INDEX IDX_3D48E037783E3463 ON dossier');
        $this->addSql('ALTER TABLE dossier DROP manager_id');
    }
}
