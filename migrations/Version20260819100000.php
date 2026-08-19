<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visit report follow-up: report_highlights ("Les plus du logement" tags
 * ticked in the report block, JSON list of PropertyHighlight values) and
 * report_reminder_sent_at (staff reminder for a done visit still missing
 * its report, idempotence marker of the daily cron). Conditional guards:
 * parallel sessions.
 */
final class Version20260819100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.report_highlights + visit.report_reminder_sent_at';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if (!$table->hasColumn('report_highlights')) {
            $this->addSql("ALTER TABLE visit ADD report_highlights JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
        }
        if (!$table->hasColumn('report_reminder_sent_at')) {
            $this->addSql("ALTER TABLE visit ADD report_reminder_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        foreach (['report_highlights', 'report_reminder_sent_at'] as $column) {
            if ($table->hasColumn($column)) {
                $this->addSql('ALTER TABLE visit DROP '.$column);
            }
        }
    }
}
