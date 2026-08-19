<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visit: rent mode flag (charges included or excluded). Conditional guard:
 * a parallel session may already have applied part of the schema.
 */
final class Version20260817090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit.rent_charges_included';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('visit')->hasColumn('rent_charges_included')) {
            $this->addSql('ALTER TABLE visit ADD rent_charges_included TINYINT(1) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('visit')->hasColumn('rent_charges_included')) {
            $this->addSql('ALTER TABLE visit DROP rent_charges_included');
        }
    }
}
