<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sent-markers for the recall reminder emails (day before, 1 hour before,
 * 5 minutes before), so the cron-driven sender never emails twice for the
 * same planned recall. All three reset when the recall date changes.
 */
final class Version20260730222040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track sent recall-reminder emails on contact submissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE contact
                ADD recall_reminder_day_sent_at DATETIME DEFAULT NULL,
                ADD recall_reminder_hour_sent_at DATETIME DEFAULT NULL,
                ADD recall_reminder_soon_sent_at DATETIME DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP recall_reminder_day_sent_at, DROP recall_reminder_hour_sent_at, DROP recall_reminder_soon_sent_at');
    }
}
