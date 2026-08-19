<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Timestamp of the last client-decision change on a visit: feeds the
 * "waiting for an answer for X days" badge while the application outcome
 * is still pending.
 */
final class Version20260818180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.client_decision_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE visit ADD client_decision_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE visit DROP client_decision_at');
    }
}
