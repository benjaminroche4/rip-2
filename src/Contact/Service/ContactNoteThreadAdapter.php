<?php

declare(strict_types=1);

namespace App\Contact\Service;

use App\Contact\Entity\ContactNote;
use App\Contact\Repository\ContactNoteRepository;
use App\Contact\Security\ContactNoteVoter;
use App\Shared\Notes\NoteThreadAdapter;

/** Plugs the contact follow-up notes into the shared thread workflow. */
final class ContactNoteThreadAdapter implements NoteThreadAdapter
{
    public function __construct(
        private readonly ContactNoteRepository $notes,
    ) {
    }

    public function find(int $noteId, int $ownerId): ?ContactNote
    {
        $note = $this->notes->find($noteId);

        return null !== $note && (int) $note->getContact()?->getId() === $ownerId ? $note : null;
    }

    public function text(object $note): string
    {
        \assert($note instanceof ContactNote);

        return $note->getText();
    }

    public function updateText(object $note, string $text): void
    {
        \assert($note instanceof ContactNote);
        $this->notes->updateText($note, $text);
    }

    public function remove(object $note): void
    {
        \assert($note instanceof ContactNote);
        $this->notes->remove($note);
    }

    public function editAttribute(): string
    {
        return ContactNoteVoter::EDIT;
    }

    public function deleteAttribute(): string
    {
        return ContactNoteVoter::DELETE;
    }
}
