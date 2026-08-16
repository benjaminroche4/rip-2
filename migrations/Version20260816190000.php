<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiche agent : cartes professionnelles loi Hoguet (T, G, S) détenues par
 * l'agent, stockées comme les spécialités (liste de valeurs d'enum).
 */
final class Version20260816190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add professional_cards on real_estate_agent';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('real_estate_agent');
        if (!$table->hasColumn('professional_cards')) {
            $this->addSql('ALTER TABLE real_estate_agent ADD professional_cards JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE real_estate_agent DROP professional_cards');
    }
}
