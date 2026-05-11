<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511143736 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add free-form note column to document_request (rendered in the PDF, optional)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_request ADD note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_request DROP note');
    }
}
