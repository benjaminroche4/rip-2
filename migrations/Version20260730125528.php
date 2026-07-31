<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a public unique reference ("CT-082820") to contact submissions. New
 * rows get a random reference from the entity; existing rows are backfilled
 * with an affine permutation of their id (387421 is coprime with 10^6, so
 * the mapping is bijective): scattered, random-looking, collision-free.
 */
final class Version20260730125528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique public reference to contact submissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD reference VARCHAR(9) DEFAULT NULL');
        $this->addSql("UPDATE contact SET reference = CONCAT('CT-', LPAD((id * 387421 + 123457) % 1000000, 6, '0'))");
        $this->addSql('ALTER TABLE contact CHANGE reference reference VARCHAR(9) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C62E638AEA34913 ON contact (reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_4C62E638AEA34913 ON contact');
        $this->addSql('ALTER TABLE contact DROP reference');
    }
}
