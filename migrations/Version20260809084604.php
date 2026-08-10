<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds user.staff_functions (business functions of a staff member: search
 * agent, visit agent, closer). MySQL backfills a new NOT NULL JSON column
 * with the JSON literal `null`, which breaks hydration into the typed array
 * property, hence the explicit backfill to an empty list.
 */
final class Version20260809084604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.staff_functions with an empty-list backfill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD staff_functions JSON NOT NULL');
        $this->addSql("UPDATE user SET staff_functions = '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP staff_functions');
    }
}
