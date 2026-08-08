<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shrinks the lead lifecycle to 4 statuses (new, in_progress, converted,
 * closed). Existing rows are remapped, history included, so the narrowed
 * enum never meets a stale value: "to recall" becomes in-progress (the
 * planned date lives in recall_at), "not converted" and "unqualified"
 * become closed (their closure reason, when set, is preserved).
 */
final class Version20260808152634 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remap contact statuses to the reduced 4-status lifecycle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE contact SET status = 'in_progress' WHERE status = 'to_recall'");
        $this->addSql("UPDATE contact SET status = 'closed' WHERE status IN ('not_converted', 'unqualified')");
        $this->addSql("UPDATE contact_event SET status = 'in_progress' WHERE status = 'to_recall'");
        $this->addSql("UPDATE contact_event SET status = 'closed' WHERE status IN ('not_converted', 'unqualified')");
    }

    public function down(Schema $schema): void
    {
        // The original distinctions cannot be reconstructed: the remap is
        // one-way by design.
        $this->skipIf(true, 'Status remap is irreversible.');
    }
}
