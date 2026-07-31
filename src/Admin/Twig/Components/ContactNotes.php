<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactEventItem;
use App\Contact\Domain\ContactNoteItem;
use App\Contact\Repository\ContactEventRepository;
use App\Contact\Repository\ContactNoteRepository;
use App\Contact\Repository\ContactRepository;
use App\Contact\Security\ContactNoteVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Follow-up notes thread on the contact detail page. Same security model as
 * the other admin live components: ROLE_ADMIN re-checked on mount and on
 * every action; per-note edit/delete goes through ContactNoteVoter (author
 * or admin).
 */
#[AsLiveComponent(name: 'Admin:ContactNotes', template: 'components/Admin/ContactNotes.html.twig')]
final class ContactNotes
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $contactId = 0;

    #[LiveProp(writable: true)]
    public string $newNote = '';

    #[LiveProp]
    public ?int $editingNoteId = null;

    #[LiveProp(writable: true)]
    public string $editingText = '';

    /** 'all' | 'notes' | 'events' */
    #[LiveProp]
    public string $feedFilter = 'all';

    /** Visible entries; grows via showMoreFeed(). */
    #[LiveProp]
    public int $feedLimit = self::FEED_PAGE;

    private const FEED_PAGE = 5;

    public function __construct(
        private readonly ContactNoteRepository $notes,
        private readonly ContactEventRepository $events,
        private readonly ContactRepository $contacts,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[LiveListener('contacts:changed')]
    public function refresh(): void
    {
        // Re-render only: a status/motif change adds an entry to the feed.
        $this->ensureAdmin();
    }

    /**
     * Notes and status/motif events merged into one thread, newest first,
     * current filter applied, capped at feedLimit entries.
     *
     * @return list<array{note: ContactNoteItem, canEdit: bool, canDelete: bool}|array{event: ContactEventItem}>
     */
    public function getFeed(): array
    {
        return \array_slice($this->fullFeed(), 0, $this->feedLimit);
    }

    /**
     * @return array{all: int, notes: int, events: int}
     */
    public function getFeedCounts(): array
    {
        $notes = \count($this->notes->listForContact($this->contactId));
        $events = \count($this->events->listForContact($this->contactId));

        return ['all' => $notes + $events, 'notes' => $notes, 'events' => $events];
    }

    public function getHiddenFeedCount(): int
    {
        return max(0, \count($this->fullFeed()) - $this->feedLimit);
    }

    #[LiveAction]
    public function filterFeed(#[LiveArg] string $filter): void
    {
        $this->ensureAdmin();

        if (\in_array($filter, ['all', 'notes', 'events'], true)) {
            $this->feedFilter = $filter;
            $this->feedLimit = self::FEED_PAGE;
        }
    }

    #[LiveAction]
    public function showMoreFeed(): void
    {
        $this->ensureAdmin();
        $this->feedLimit += 10;
    }

    /**
     * @return list<array{note: ContactNoteItem, canEdit: bool, canDelete: bool}|array{event: ContactEventItem}>
     */
    private function fullFeed(): array
    {
        $rows = [];

        if ('events' !== $this->feedFilter) {
            $rows = array_map(
                fn (ContactNoteItem $note): array => [
                    'note' => $note,
                    'canEdit' => $this->security->isGranted(ContactNoteVoter::EDIT, $note),
                    'canDelete' => $this->security->isGranted(ContactNoteVoter::DELETE, $note),
                ],
                $this->notes->listForContact($this->contactId),
            );
        }

        if ('notes' !== $this->feedFilter) {
            foreach ($this->events->listForContact($this->contactId) as $event) {
                $rows[] = ['event' => $event];
            }
        }

        usort($rows, static fn (array $a, array $b): int => ($b['note'] ?? $b['event'])->createdAt <=> ($a['note'] ?? $a['event'])->createdAt);

        return $rows;
    }

    #[LiveAction]
    public function add(): void
    {
        $this->ensureAdmin();

        $text = trim($this->newNote);
        $contact = $this->contacts->find($this->contactId);
        if ('' === $text || null === $contact) {
            return;
        }

        $user = $this->currentUser();
        $this->notes->add(
            $contact,
            $text,
            (int) $user->getId(),
            $this->displayName($user),
            $user->getAvatarFilename(),
        );
        $this->newNote = '';

        // Clears the leave-guard dirty flag on the front.
        $this->dispatchBrowserEvent('contact-notes:saved');
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $note = $this->notes->find($id);
        if (null === $note || (int) $note->getContact()?->getId() !== $this->contactId) {
            return;
        }
        if (!$this->security->isGranted(ContactNoteVoter::EDIT, $note)) {
            throw new AccessDeniedException('Only the author or an admin can edit a note.');
        }

        $this->editingNoteId = $id;
        $this->editingText = $note->getText();
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->ensureAdmin();
        $this->editingNoteId = null;
        $this->editingText = '';
    }

    #[LiveAction]
    public function saveEdit(): void
    {
        $this->ensureAdmin();

        $note = null !== $this->editingNoteId ? $this->notes->find($this->editingNoteId) : null;
        $text = trim($this->editingText);
        if (null === $note || '' === $text) {
            return;
        }
        if (!$this->security->isGranted(ContactNoteVoter::EDIT, $note)) {
            throw new AccessDeniedException('Only the author or an admin can edit a note.');
        }

        $this->notes->updateText($note, $text);
        $this->editingNoteId = null;
        $this->editingText = '';

        $this->dispatchBrowserEvent('contact-notes:saved');
    }

    #[LiveAction]
    public function delete(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $note = $this->notes->find($id);
        if (null === $note || (int) $note->getContact()?->getId() !== $this->contactId) {
            return;
        }
        if (!$this->security->isGranted(ContactNoteVoter::DELETE, $note)) {
            throw new AccessDeniedException('Only the author or an admin can delete a note.');
        }

        $this->notes->remove($note);
        if ($this->editingNoteId === $id) {
            $this->editingNoteId = null;
            $this->editingText = '';
        }
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Admin access required.');
        }

        return $user;
    }

    private function displayName(User $user): string
    {
        $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));

        return '' !== $fullName ? $fullName : (string) $user->getEmail();
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
