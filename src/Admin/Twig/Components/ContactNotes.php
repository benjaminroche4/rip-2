<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Contact\Domain\ContactNoteItem;
use App\Contact\Repository\ContactNoteRepository;
use App\Contact\Repository\ContactRepository;
use App\Contact\Security\ContactNoteVoter;
use App\Contact\Service\ContactNoteThreadAdapter;
use App\Shared\Notes\NoteThreadService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
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
    use ContactsSectionGuard;
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

    /** Note being answered (opens the inline reply composer under it). */
    #[LiveProp]
    public ?int $replyingToId = null;

    #[LiveProp(writable: true)]
    public string $replyText = '';

    /** Visible entries; grows via showMoreFeed(). */
    #[LiveProp]
    public int $feedLimit = self::FEED_PAGE;

    private const FEED_PAGE = 5;

    public function __construct(
        private readonly ContactNoteRepository $notes,
        private readonly ContactRepository $contacts,
        private readonly Security $security,
        private readonly \App\Contact\Repository\ContactEventRepository $events,
        private readonly TranslatorInterface $translator,
        private readonly NoteThreadService $thread,
        private readonly ContactNoteThreadAdapter $threadAdapter,
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
     * Notes thread, newest first, capped at feedLimit top-level entries.
     * Replies ride along under their parent note, oldest first (a
     * conversation reads top to bottom). The action history (status,
     * motifs, recap emails) lives in the follow-up timeline, not here.
     *
     * @return list<array{note: ContactNoteItem, canEdit: bool, canDelete: bool, replies: list<array{note: ContactNoteItem, canEdit: bool, canDelete: bool}>}>
     */
    public function getFeed(): array
    {
        return \array_slice($this->fullFeed(), 0, $this->feedLimit);
    }

    public function getHiddenFeedCount(): int
    {
        return max(0, \count($this->fullFeed()) - $this->feedLimit);
    }

    #[LiveAction]
    public function showMoreFeed(): void
    {
        $this->ensureAdmin();
        $this->feedLimit += 10;
    }

    /**
     * @return list<array{note: ContactNoteItem, canEdit: bool, canDelete: bool, replies: list<array{note: ContactNoteItem, canEdit: bool, canDelete: bool}>}>
     */
    private function fullFeed(): array
    {
        $all = $this->notes->listForContact($this->contactId);

        // Replies grouped under their parent, chronological (repo order is
        // newest first, so reversed).
        $repliesByParent = [];
        foreach (array_reverse($all) as $note) {
            if (null !== $note->parentId) {
                $repliesByParent[$note->parentId][] = $this->toRow($note);
            }
        }

        $rows = [];
        foreach ($all as $note) {
            if (null !== $note->parentId) {
                continue;
            }
            $row = $this->toRow($note);
            $row['replies'] = $repliesByParent[$note->id] ?? [];
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array{note: ContactNoteItem, canEdit: bool, canDelete: bool}
     */
    private function toRow(ContactNoteItem $note): array
    {
        return [
            'note' => $note,
            'canEdit' => $this->security->isGranted(ContactNoteVoter::EDIT, $note),
            'canDelete' => $this->security->isGranted(ContactNoteVoter::DELETE, $note),
        ];
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

        $author = $this->thread->currentAuthor();
        $this->notes->add(
            $contact,
            $text,
            $author->id,
            $author->displayName,
            $author->avatarFilename,
        );
        $this->newNote = '';

        // Clears the leave-guard dirty flag on the front.
        $this->dispatchBrowserEvent('contact-notes:saved');
        $this->dispatchBrowserEvent('contact-notes:count', [
            'count' => \count($this->notes->listForContact($this->contactId)),
        ]);
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.noteAdded')]);
    }

    /** Opens the inline reply composer under a note. */
    #[LiveAction]
    public function startReply(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        // Silent no-op on a stale id or a note from another thread.
        $parent = $this->notes->find($id);
        if (null === $parent || (int) $parent->getContact()?->getId() !== $this->contactId) {
            return;
        }

        $this->replyingToId = $id;
        $this->replyText = '';
    }

    #[LiveAction]
    public function cancelReply(): void
    {
        $this->ensureAdmin();
        $this->replyingToId = null;
        $this->replyText = '';
    }

    /** Persists the reply under its parent note (depth capped at one). */
    #[LiveAction]
    public function addReply(): void
    {
        $this->ensureAdmin();

        $text = trim($this->replyText);
        $contact = $this->contacts->find($this->contactId);
        $parent = null !== $this->replyingToId ? $this->notes->find($this->replyingToId) : null;
        if ('' === $text || null === $contact || null === $parent
            || (int) $parent->getContact()?->getId() !== $this->contactId) {
            return;
        }

        $author = $this->thread->currentAuthor();
        $this->notes->add(
            $contact,
            $text,
            $author->id,
            $author->displayName,
            $author->avatarFilename,
            $parent,
        );
        $this->replyingToId = null;
        $this->replyText = '';

        $this->dispatchBrowserEvent('contact-notes:saved');
        $this->dispatchBrowserEvent('contact-notes:count', [
            'count' => \count($this->notes->listForContact($this->contactId)),
        ]);
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.noteAdded')]);
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $text = $this->thread->beginEdit($this->threadAdapter, $id, $this->contactId);
        if (null === $text) {
            return;
        }

        $this->editingNoteId = $id;
        $this->editingText = $text;
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

        if (!$this->thread->saveEdit($this->threadAdapter, $this->editingNoteId, $this->editingText, $this->contactId)) {
            return;
        }

        $this->editingNoteId = null;
        $this->editingText = '';

        $this->dispatchBrowserEvent('contact-notes:saved');
        $this->dispatchBrowserEvent('contact-notes:count', [
            'count' => \count($this->notes->listForContact($this->contactId)),
        ]);
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.noteUpdated')]);
    }

    #[LiveAction]
    public function delete(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        if (!$this->thread->delete($this->threadAdapter, $id, $this->contactId)) {
            return;
        }

        if ($this->editingNoteId === $id) {
            $this->editingNoteId = null;
            $this->editingText = '';
        }
        if ($this->replyingToId === $id) {
            $this->replyingToId = null;
            $this->replyText = '';
        }

        $this->dispatchBrowserEvent('contact-notes:count', [
            'count' => \count($this->notes->listForContact($this->contactId)),
        ]);
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.noteDeleted')]);
    }

    /**
     * Audit trail (status changes, recap emails), oldest first — rendered
     * as the chronologie under the notes thread in the drawer.
     *
     * @return list<array{text: string, authorName: string|null, authorAvatar: string|null, hint: string|null, createdAt: \DateTimeImmutable, dotClass: string}>
     */
    public function getHistory(): array
    {
        $rows = [];

        // Point de départ : la réception de la demande (IP au survol).
        $contact = $this->contacts->find($this->contactId);
        if (null !== $contact && null !== $contact->getCreatedAt()) {
            $rows[] = [
                'text' => $this->translator->trans('admin.contacts.card.created'),
                // Pas un auteur : l'IP de la soumission reste une info
                // technique, elle vit en infobulle (jamais d'avatar "I").
                'authorName' => null,
                'authorAvatar' => null,
                'hint' => null !== $contact->getIp() ? 'IP : '.$contact->getIp() : null,
                'createdAt' => $contact->getCreatedAt(),
                'dotClass' => 'bg-gray-300',
            ];
        }

        foreach ($this->events->listForContact($this->contactId) as $event) {
            if (null !== $event->kind) {
                $text = $this->translator->trans('admin.contacts.events.'.$event->kind);
                if (null !== $event->detail) {
                    $text .= ' : '.$event->detail;
                }
                $dotClass = 'bg-blue-400';
            } elseif (null !== $event->status) {
                $text = $this->translator->trans('admin.contacts.events.status', [
                    '%status%' => $this->translator->trans($event->status->labelKey()),
                ]);
                if (null !== $event->closureReason) {
                    $text .= ' : '.$this->translator->trans($event->closureReason->labelKey());
                }
                $dotClass = $event->status->dotClass();
            } elseif (null !== $event->closureReason) {
                $text = $this->translator->trans('admin.contacts.events.reason', [
                    '%reason%' => $this->translator->trans($event->closureReason->labelKey()),
                ]);
                $dotClass = 'bg-amber-400';
            } else {
                continue;
            }
            $rows[] = [
                'text' => $text,
                'authorName' => $event->authorName,
                'authorAvatar' => $event->authorAvatar,
                'hint' => null,
                'createdAt' => $event->createdAt,
                'dotClass' => $dotClass,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['createdAt'] <=> $b['createdAt']);

        return $rows;
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_CONTACTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
