<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260802183642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dossier.reference (DS-######) and dossier.pairing_code (6-char code), unique';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier ADD reference VARCHAR(9) NOT NULL, ADD pairing_code VARCHAR(6) NOT NULL');
        // Backfill rows created before this migration with random identifiers
        // so the unique indexes below can be created (id-seeded to avoid
        // collisions within the batch).
        $this->addSql("UPDATE dossier SET reference = CONCAT('DS-', LPAD(id MOD 1000000, 6, '0')), pairing_code = UPPER(SUBSTRING(MD5(CONCAT(id, RAND())), 1, 6)) WHERE reference = ''");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3D48E037AEA34913 ON dossier (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3D48E037D0ACCEDE ON dossier (pairing_code)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_3D48E037AEA34913 ON dossier');
        $this->addSql('DROP INDEX UNIQ_3D48E037D0ACCEDE ON dossier');
        $this->addSql('ALTER TABLE dossier DROP reference, DROP pairing_code');
    }
}
