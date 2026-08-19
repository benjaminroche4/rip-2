<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visit: outcome of the application filed on the property (accepted /
 * refused, null = pending), only meaningful when the client positioned
 * themselves. Conditional guard: parallel sessions.
 */
final class Version20260818150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.application_outcome (nullable enum string)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if (!$table->hasColumn('application_outcome')) {
            $this->addSql('ALTER TABLE visit ADD application_outcome VARCHAR(20) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        if ($table->hasColumn('application_outcome')) {
            $this->addSql('ALTER TABLE visit DROP application_outcome');
        }
    }
}
