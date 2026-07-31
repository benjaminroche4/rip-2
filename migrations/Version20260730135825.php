<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * First-response timestamp for contact submissions: set once, on the first
 * transition out of "new", and never overwritten afterwards (unlike
 * status_changed_at). Powers the dashboard "response time" KPI. Existing
 * treated rows are seeded from status_changed_at, the best approximation
 * available.
 */
final class Version20260730135825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add first_treated_at to contact submissions for response-time KPIs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD first_treated_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE contact SET first_treated_at = status_changed_at WHERE status <> 'new' AND status_changed_at IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP first_treated_at');
    }
}
