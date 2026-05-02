<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Ulid;

final class Version20260430170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.unique_id (ULID) for opaque public-facing references in admin URLs and any future external link.';
    }

    /**
     * Disable the implicit transaction so the back-fill runs between the
     * "ADD column nullable" and "ALTER to NOT NULL + UNIQUE" statements
     * without sitting inside a single locked transaction. MySQL DDL is
     * non-transactional anyway and would auto-commit between steps.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE user ADD unique_id BINARY(16) DEFAULT NULL'
        );

        $ids = $this->connection->fetchFirstColumn('SELECT id FROM user');
        foreach ($ids as $id) {
            $this->connection->executeStatement(
                'UPDATE user SET unique_id = :ulid WHERE id = :id',
                ['ulid' => (new Ulid())->toBinary(), 'id' => $id],
            );
        }

        $this->connection->executeStatement(
            'ALTER TABLE user MODIFY unique_id BINARY(16) NOT NULL'
        );
        // Index name follows Doctrine's auto-naming convention so subsequent
        // doctrine:schema:validate runs stay clean.
        $this->connection->executeStatement(
            'CREATE UNIQUE INDEX UNIQ_8D93D649E3C68343 ON user (unique_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649E3C68343 ON user');
        $this->addSql('ALTER TABLE user DROP unique_id');
    }
}
