<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the CC/HC budget qualifier from the search criteria. The stored
 * qualifier is not thrown away: it is appended to the project note first,
 * so a budget recorded "hors charges" keeps its meaning after the column
 * is gone (a 1800 EUR HC budget reads ~200 EUR off otherwise).
 */
final class Version20260808113222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop dossier_search.budget_charges, preserving the qualifier in the note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE dossier_search
            SET note = TRIM(BOTH '\n' FROM CONCAT(COALESCE(note, ''), '\n', CASE budget_charges
                WHEN 'included' THEN 'Budget charges comprises'
                ELSE 'Budget hors charges'
            END))
            WHERE budget_charges IS NOT NULL
            SQL);
        $this->addSql('ALTER TABLE dossier_search DROP budget_charges');
    }

    public function down(Schema $schema): void
    {
        // The column comes back empty: the data now lives in the note.
        $this->addSql('ALTER TABLE dossier_search ADD budget_charges VARCHAR(10) DEFAULT NULL');
    }
}
