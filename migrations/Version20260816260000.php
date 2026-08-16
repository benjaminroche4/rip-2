<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trace of the last presentation email sent to a directory agent, shown in
 * the confirmation modal to avoid accidental duplicate sends. Conditional,
 * like the rest of the batch (parallel sessions may collide).
 */
final class Version20260816260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add real_estate_agent.intro_email_sent_at (last intro email, idempotent)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('real_estate_agent');
        if (!$table->hasColumn('intro_email_sent_at')) {
            $this->addSql('ALTER TABLE real_estate_agent ADD intro_email_sent_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent DROP intro_email_sent_at');
    }
}
