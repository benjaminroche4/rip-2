<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Google Calendar mirror of a visit: event id in the central agenda, twin
 * event id in the assignee's personal agenda, and the email that personal
 * event was created under. Conditional (parallel sessions may collide).
 */
final class Version20260817130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.calendar_central_event_id / calendar_assignee_event_id / calendar_assignee_email (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if (!$table->hasColumn('calendar_central_event_id')) {
            $this->addSql('ALTER TABLE visit ADD calendar_central_event_id VARCHAR(255) DEFAULT NULL');
        }
        if (!$table->hasColumn('calendar_assignee_event_id')) {
            $this->addSql('ALTER TABLE visit ADD calendar_assignee_event_id VARCHAR(255) DEFAULT NULL');
        }
        if (!$table->hasColumn('calendar_assignee_email')) {
            $this->addSql('ALTER TABLE visit ADD calendar_assignee_email VARCHAR(180) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE visit DROP calendar_central_event_id');
        $this->addSql('ALTER TABLE visit DROP calendar_assignee_event_id');
        $this->addSql('ALTER TABLE visit DROP calendar_assignee_email');
    }
}
