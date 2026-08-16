<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agency location profile: favorite districts (ParisDistricts CSV codes)
 * and address coordinates for the detail-page map.
 */
final class Version20260816120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agency.areas, agency.latitude, agency.longitude';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agency ADD areas VARCHAR(255) DEFAULT NULL, ADD latitude DOUBLE PRECISION DEFAULT NULL, ADD longitude DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agency DROP areas, DROP latitude, DROP longitude');
    }
}
