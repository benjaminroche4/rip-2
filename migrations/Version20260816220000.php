<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Références publiques des agents (AG-XXXXXX) et agences (AY-XXXXXX), même
 * format aléatoire que les autres références du site (DS-, CT-). Les lignes
 * existantes sont backfillées en postUp (tirage aléatoire + garde d'unicité
 * en mémoire, l'index unique reste le verrou final).
 */
final class Version20260816220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public references on real_estate_agent (AG-) and agency (AY-)';
    }

    public function up(Schema $schema): void
    {
        $agents = $schema->getTable('real_estate_agent');
        if (!$agents->hasColumn('reference')) {
            $this->addSql('ALTER TABLE real_estate_agent ADD reference VARCHAR(9) DEFAULT NULL');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_AGENT_REFERENCE ON real_estate_agent (reference)');
        }
        $agencies = $schema->getTable('agency');
        if (!$agencies->hasColumn('reference')) {
            $this->addSql('ALTER TABLE agency ADD reference VARCHAR(9) DEFAULT NULL');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_AGENCY_REFERENCE ON agency (reference)');
        }
    }

    public function postUp(Schema $schema): void
    {
        foreach ([['real_estate_agent', 'AG'], ['agency', 'AY']] as [$table, $prefix]) {
            $ids = $this->connection->fetchFirstColumn(\sprintf('SELECT id FROM %s WHERE reference IS NULL', $table));
            $taken = array_flip($this->connection->fetchFirstColumn(\sprintf('SELECT reference FROM %s WHERE reference IS NOT NULL', $table)));
            foreach ($ids as $id) {
                do {
                    $reference = \sprintf('%s-%06d', $prefix, random_int(0, 999999));
                } while (isset($taken[$reference]));
                $taken[$reference] = true;
                $this->connection->executeStatement(
                    \sprintf('UPDATE %s SET reference = :reference WHERE id = :id', $table),
                    ['reference' => $reference, 'id' => $id],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_AGENT_REFERENCE ON real_estate_agent');
        $this->addSql('ALTER TABLE real_estate_agent DROP reference');
        $this->addSql('DROP INDEX UNIQ_AGENCY_REFERENCE ON agency');
        $this->addSql('ALTER TABLE agency DROP reference');
    }
}
