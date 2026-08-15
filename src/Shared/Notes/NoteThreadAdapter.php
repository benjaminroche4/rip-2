<?php

declare(strict_types=1);

namespace App\Shared\Notes;

/**
 * Contract a bounded context implements to plug its follow-up notes into
 * the shared thread workflow (NoteThreadService): a thin wrapper around the
 * context's own note repository and voter, keeping the entities typed
 * inside the context.
 */
interface NoteThreadAdapter
{
    /**
     * The note entity, or null when the id is unknown or the note is
     * attached to another owner (contact/dossier) than $ownerId: a stale
     * DOM can post anything, both cases are silent no-ops.
     */
    public function find(int $noteId, int $ownerId): ?object;

    public function text(object $note): string;

    public function updateText(object $note, string $text): void;

    public function remove(object $note): void;

    /** Voter attribute guarding edits (author or admin). */
    public function editAttribute(): string;

    /** Voter attribute guarding deletions (author or admin). */
    public function deleteAttribute(): string;
}
