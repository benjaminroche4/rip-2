<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Entity\DossierNote;
use App\Dossier\Repository\DossierNoteRepository;
use App\Dossier\Security\DossierNoteVoter;
use App\Shared\Notes\NoteThreadAdapter;

/** Plugs the dossier follow-up notes into the shared thread workflow. */
final class DossierNoteThreadAdapter implements NoteThreadAdapter
{
    public function __construct(
        private readonly DossierNoteRepository $notes,
    ) {
    }

    public function find(int $noteId, int $ownerId): ?DossierNote
    {
        $note = $this->notes->find($noteId);

        return null !== $note && (int) $note->getDossier()?->getId() === $ownerId ? $note : null;
    }

    public function text(object $note): string
    {
        \assert($note instanceof DossierNote);

        return $note->getText();
    }

    public function updateText(object $note, string $text): void
    {
        \assert($note instanceof DossierNote);
        $this->notes->updateText($note, $text);
    }

    public function remove(object $note): void
    {
        \assert($note instanceof DossierNote);
        $this->notes->remove($note);
    }

    public function editAttribute(): string
    {
        return DossierNoteVoter::EDIT;
    }

    public function deleteAttribute(): string
    {
        return DossierNoteVoter::DELETE;
    }
}
