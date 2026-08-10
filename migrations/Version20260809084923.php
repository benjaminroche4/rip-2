<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809084923 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the visit table (dossier FK, optional real-estate agent, geocoded address)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE visit (id INT AUTO_INCREMENT NOT NULL, scheduled_at DATETIME NOT NULL, address VARCHAR(255) NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, created_at DATETIME NOT NULL, dossier_id INT NOT NULL, agent_id INT DEFAULT NULL, INDEX IDX_437EE939611C0C56 (dossier_id), INDEX IDX_437EE9393414710B (agent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE visit ADD CONSTRAINT FK_437EE939611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE visit ADD CONSTRAINT FK_437EE9393414710B FOREIGN KEY (agent_id) REFERENCES real_estate_agent (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE visit DROP FOREIGN KEY FK_437EE939611C0C56');
        $this->addSql('ALTER TABLE visit DROP FOREIGN KEY FK_437EE9393414710B');
        $this->addSql('DROP TABLE visit');
    }
}
