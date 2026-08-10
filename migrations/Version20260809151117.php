<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809151117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remap contact next_step values to the simplified 5-step list';
    }

    public function up(Schema $schema): void
    {
        // call → recontact, video_call → visio, proposal → quote_sent,
        // prepare_file → quote_preparation; visit/awaiting_client have no
        // equivalent in the simplified list and fall back to other.
        $this->addSql(<<<'SQL'
                UPDATE contact SET next_step = CASE next_step
                    WHEN 'call' THEN 'recontact'
                    WHEN 'video_call' THEN 'visio'
                    WHEN 'proposal' THEN 'quote_sent'
                    WHEN 'prepare_file' THEN 'quote_preparation'
                    WHEN 'visit' THEN 'other'
                    WHEN 'awaiting_client' THEN 'other'
                    ELSE next_step
                END
                WHERE next_step IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'The old next-step granularity cannot be reconstructed.');
    }
}
