<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515101904 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add situation column (Situation backed-enum) to user table for the registration step 2 professional-situation question.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD situation VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP situation');
    }
}
