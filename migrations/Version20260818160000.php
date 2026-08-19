<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visit: follow-up of the client decision. decision_deadline (date only,
 * "thinking until when?") and refusal_origin (landlord or client), both
 * reset when the decision leaves their state. Conditional guards: parallel
 * sessions.
 */
final class Version20260818160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.decision_deadline (date) + visit.refusal_origin (enum string)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if (!$table->hasColumn('decision_deadline')) {
            $this->addSql('ALTER TABLE visit ADD decision_deadline DATE DEFAULT NULL');
        }
        if (!$table->hasColumn('refusal_origin')) {
            $this->addSql('ALTER TABLE visit ADD refusal_origin VARCHAR(20) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        foreach (['decision_deadline', 'refusal_origin'] as $column) {
            if ($table->hasColumn($column)) {
                $this->addSql('ALTER TABLE visit DROP '.$column);
            }
        }
    }
}
