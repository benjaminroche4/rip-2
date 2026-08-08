<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Lifecycle of a requested piece: asked to the tenant, deposited (waiting
 * for review), then validated or refused by the manager. A refused piece is
 * shown as "to deposit again" on the public deposit page, and a new upload
 * puts it back to Received.
 */
enum DossierDocumentStatus: string
{
    case Requested = 'requested';
    case Received = 'received';
    case Validated = 'validated';
    case Refused = 'refused';

    public function labelKey(): string
    {
        return 'admin.dossiers.show.modules.file.status.'.$this->value;
    }

    /** Label shown to the tenant on the public deposit page. */
    public function publicLabelKey(): string
    {
        return 'deposit.documents.status.'.$this->value;
    }

    /** A new upload still makes sense for these statuses. */
    public function acceptsUpload(): bool
    {
        return self::Validated !== $this;
    }
}
