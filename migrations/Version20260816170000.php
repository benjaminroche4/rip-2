<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiche agent : instantanés du créateur et du dernier modificateur
 * (affichés sur la fiche détail uniquement). Conditionnelle : une migration
 * fantôme d'une session parallèle a déjà posé ces colonnes sur certains
 * environnements sans laisser de fichier rejouable.
 */
final class Version20260816170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created_by_name / updated_by_name snapshots on real_estate_agent (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('real_estate_agent');
        if (!$table->hasColumn('created_by_name')) {
            $this->addSql('ALTER TABLE real_estate_agent ADD created_by_name VARCHAR(100) DEFAULT NULL');
        }
        if (!$table->hasColumn('updated_by_name')) {
            $this->addSql('ALTER TABLE real_estate_agent ADD updated_by_name VARCHAR(100) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent DROP created_by_name, DROP updated_by_name');
    }
}
