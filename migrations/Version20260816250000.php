<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agences favorites : horodatage du marquage (null = pas favori), symétrique
 * du favori agent. Conditionnelle, comme le reste du lot (collisions
 * possibles avec une session parallèle).
 */
final class Version20260816250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add favorited_at on agency (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('agency');
        if (!$table->hasColumn('favorited_at')) {
            $this->addSql('ALTER TABLE agency ADD favorited_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agency DROP favorited_at');
    }
}
