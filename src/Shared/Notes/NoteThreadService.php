<?php

declare(strict_types=1);

namespace App\Shared\Notes;

use App\Auth\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Follow-up notes workflow shared by the contact and dossier threads:
 * author resolution and the guarded edit/delete flow (per-note voter,
 * silent no-op on stale ids). Each component keeps its own repository
 * (via a NoteThreadAdapter), its section guard and its browser events.
 */
final class NoteThreadService
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    /** Staff member signing the mutation. */
    public function currentAuthor(): NoteAuthor
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Admin access required.');
        }

        return NoteAuthor::fromUser($user);
    }

    /**
     * Starts the inline edit: returns the note's current text, or null when
     * the note is unknown or owned by another thread (silent no-op).
     */
    public function beginEdit(NoteThreadAdapter $notes, int $noteId, int $ownerId): ?string
    {
        $note = $notes->find($noteId, $ownerId);
        if (null === $note) {
            return null;
        }
        if (!$this->security->isGranted($notes->editAttribute(), $note)) {
            throw new AccessDeniedException('Only the author or an admin can edit a note.');
        }

        return $notes->text($note);
    }

    /**
     * Persists the edited text; false when there is nothing to save (blank
     * text, unknown or stale note id).
     */
    public function saveEdit(NoteThreadAdapter $notes, ?int $noteId, string $text, int $ownerId): bool
    {
        $note = null !== $noteId ? $notes->find($noteId, $ownerId) : null;
        $text = trim($text);
        if (null === $note || '' === $text) {
            return false;
        }
        if (!$this->security->isGranted($notes->editAttribute(), $note)) {
            throw new AccessDeniedException('Only the author or an admin can edit a note.');
        }

        $notes->updateText($note, $text);

        return true;
    }

    /** Deletes the note; false when the id is unknown or stale. */
    public function delete(NoteThreadAdapter $notes, int $noteId, int $ownerId): bool
    {
        $note = $notes->find($noteId, $ownerId);
        if (null === $note) {
            return false;
        }
        if (!$this->security->isGranted($notes->deleteAttribute(), $note)) {
            throw new AccessDeniedException('Only the author or an admin can delete a note.');
        }

        $notes->remove($note);

        return true;
    }
}
