<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811100355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE visit ADD assignee_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE visit ADD CONSTRAINT FK_437EE93959EC7D60 FOREIGN KEY (assignee_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_437EE93959EC7D60 ON visit (assignee_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE visit DROP FOREIGN KEY FK_437EE93959EC7D60');
        $this->addSql('DROP INDEX IDX_437EE93959EC7D60 ON visit');
        $this->addSql('ALTER TABLE visit DROP assignee_id');
    }
}
