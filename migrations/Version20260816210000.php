<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiche agent indépendant : adresse, coordonnées et quartiers favoris,
 * mêmes colonnes que sur l'agence. Conditionnelle, comme le reste du lot
 * (collisions possibles avec une session parallèle).
 */
final class Version20260816210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add address, latitude, longitude and areas on real_estate_agent (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('real_estate_agent');
        $adds = [
            'address' => 'VARCHAR(255) DEFAULT NULL',
            'areas' => 'VARCHAR(255) DEFAULT NULL',
            'latitude' => 'DOUBLE PRECISION DEFAULT NULL',
            'longitude' => 'DOUBLE PRECISION DEFAULT NULL',
        ];
        foreach ($adds as $column => $definition) {
            if (!$table->hasColumn($column)) {
                $this->addSql(\sprintf('ALTER TABLE real_estate_agent ADD %s %s', $column, $definition));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent DROP address, DROP areas, DROP latitude, DROP longitude');
    }
}
