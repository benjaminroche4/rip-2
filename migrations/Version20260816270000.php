<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Le bien en détail" du formulaire de visite : caractéristiques optionnelles
 * du bien visité. Conditionnelle (guards hasColumn), comme le reste du lot :
 * une session parallèle partage la base.
 */
final class Version20260816270000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add surface, floor, property_kind, furnishing, lease_type, rent_excluding_charges and charges on visit (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('visit');
        $adds = [
            'surface' => 'DOUBLE PRECISION DEFAULT NULL',
            'floor' => 'INT DEFAULT NULL',
            'property_kind' => 'VARCHAR(30) DEFAULT NULL',
            'furnishing' => 'VARCHAR(20) DEFAULT NULL',
            'lease_type' => 'VARCHAR(20) DEFAULT NULL',
            'rent_excluding_charges' => 'DOUBLE PRECISION DEFAULT NULL',
            'charges' => 'DOUBLE PRECISION DEFAULT NULL',
        ];
        foreach ($adds as $column => $definition) {
            if (!$table->hasColumn($column)) {
                $this->addSql(\sprintf('ALTER TABLE visit ADD %s %s', $column, $definition));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE visit DROP surface, DROP floor, DROP property_kind, DROP furnishing, DROP lease_type, DROP rent_excluding_charges, DROP charges');
    }
}
