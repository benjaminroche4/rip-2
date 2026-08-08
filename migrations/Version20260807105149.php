<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807105149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional pets, household typology and early move-in criteria to the dossier search.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_search ADD pets VARCHAR(10) DEFAULT NULL, ADD household_type VARCHAR(30) DEFAULT NULL, ADD early_move_in VARCHAR(10) DEFAULT NULL, ADD lease_types VARCHAR(255) DEFAULT NULL, ADD min_surface INT DEFAULT NULL, ADD min_bedrooms SMALLINT DEFAULT NULL, ADD elevator VARCHAR(10) DEFAULT NULL, ADD outdoor_spaces VARCHAR(255) DEFAULT NULL, ADD parking VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_search DROP pets, DROP household_type, DROP early_move_in, DROP lease_types, DROP min_surface, DROP min_bedrooms, DROP elevator, DROP outdoor_spaces, DROP parking');
    }
}
