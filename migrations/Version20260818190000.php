<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visit follow-up emails: client_note_sent_at (last time the client note
 * was emailed to the dossier contacts, kept when the note changes) and
 * decision_reminder_sent_at (staff reminder for an overdue thinking
 * deadline, idempotence marker of the cron). Conditional guards: parallel
 * sessions.
 */
final class Version20260818190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.client_note_sent_at + visit.decision_reminder_sent_at';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if (!$table->hasColumn('client_note_sent_at')) {
            $this->addSql("ALTER TABLE visit ADD client_note_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        }
        if (!$table->hasColumn('decision_reminder_sent_at')) {
            $this->addSql("ALTER TABLE visit ADD decision_reminder_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        foreach (['client_note_sent_at', 'decision_reminder_sent_at'] as $column) {
            if ($table->hasColumn($column)) {
                $this->addSql('ALTER TABLE visit DROP '.$column);
            }
        }
    }
}
