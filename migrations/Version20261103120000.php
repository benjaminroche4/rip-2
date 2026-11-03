<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "On vous rappelle" call-back form: its answers are stored on the contact
 * row (all nullable, the contact form leaves them empty) with the source form.
 */
final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the call-back form answers and the source form to contact';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD source VARCHAR(20) DEFAULT NULL, ADD situation VARCHAR(20) DEFAULT NULL, ADD settle_timing VARCHAR(20) DEFAULT NULL, ADD furnishing VARCHAR(20) DEFAULT NULL, ADD rooms VARCHAR(20) DEFAULT NULL, ADD guarantor VARCHAR(20) DEFAULT NULL, ADD rent_budget VARCHAR(20) DEFAULT NULL, ADD contact_method VARCHAR(20) DEFAULT NULL, ADD contact_slot VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP source, DROP situation, DROP settle_timing, DROP furnishing, DROP rooms, DROP guarantor, DROP rent_budget, DROP contact_method, DROP contact_slot');
    }
}
