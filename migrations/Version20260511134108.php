<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511134108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add role column (tenant|guarantor) to person_request';
    }

    public function up(Schema $schema): void
    {
        // Add with a default so existing rows (if any) get a valid value,
        // then drop the default to keep the column "required at write time".
        $this->addSql("ALTER TABLE person_request ADD role VARCHAR(20) NOT NULL DEFAULT 'tenant'");
        $this->addSql('ALTER TABLE person_request ALTER role DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person_request DROP role');
    }
}
