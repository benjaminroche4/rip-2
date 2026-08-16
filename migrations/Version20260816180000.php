<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiche agent : instantanés d'avatar du créateur et du dernier modificateur
 * (affichés avec le nom dans la card de traçabilité). Conditionnelle par
 * cohérence avec la migration précédente du même lot.
 */
final class Version20260816180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created_by_avatar / updated_by_avatar snapshots on real_estate_agent (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('real_estate_agent');
        if (!$table->hasColumn('created_by_avatar')) {
            $this->addSql('ALTER TABLE real_estate_agent ADD created_by_avatar VARCHAR(255) DEFAULT NULL');
        }
        if (!$table->hasColumn('updated_by_avatar')) {
            $this->addSql('ALTER TABLE real_estate_agent ADD updated_by_avatar VARCHAR(255) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent DROP created_by_avatar, DROP updated_by_avatar');
    }
}
