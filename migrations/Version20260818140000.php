<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visit: client decision about the property (thinking / positioning /
 * refused), captured in the "Retour client" block of the report card.
 * Conditional guard: parallel sessions.
 */
final class Version20260818140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.client_decision (nullable enum string)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if (!$table->hasColumn('client_decision')) {
            $this->addSql('ALTER TABLE visit ADD client_decision VARCHAR(20) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if ($table->hasColumn('client_decision')) {
            $this->addSql('ALTER TABLE visit DROP client_decision');
        }
    }
}
