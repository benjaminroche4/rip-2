<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persists the package/offer picked on the public contact form (accompagne
 * or confie, only set for housing-search requests). Until now it was only
 * forwarded in the notification email and lost afterwards.
 */
final class Version20260730152832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist the offer chosen on the contact form.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD offer VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP offer');
    }
}
