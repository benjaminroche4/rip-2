<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agents favoris : horodatage du marquage (null = pas favori), utilisé par
 * le tri/filtre de l'annuaire.
 */
final class Version20260816230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add favorited_at on real_estate_agent';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('real_estate_agent');
        if (!$table->hasColumn('favorited_at')) {
            $this->addSql('ALTER TABLE real_estate_agent ADD favorited_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent DROP favorited_at');
    }
}
