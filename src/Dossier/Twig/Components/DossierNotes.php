<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Auth\Entity\User;
use App\Auth\Repository\UserRepository;
use App\Dossier\Domain\DossierManagerView;
use App\Dossier\Domain\DossierNoteView;
use App\Dossier\Domain\DossierStatus;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierEvent;
use App\Dossier\Repository\DossierEventRepository;
use App\Dossier\Repository\DossierNoteRepository;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Security\DossierNoteVoter;
use App\Dossier\Service\DocumentStorage;
use App\Dossier\Service\DossierEventLogger;
use App\Dossier\Service\DossierNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
 * "Suivi" card on the dossier detail page: manager assignment chips plus the
 * follow-up notes thread (add / edit / delete, author or admin via
 * DossierNoteVoter). Mirrors Admin:ContactFollowUp + Admin:ContactNotes.
 */
#[AsLiveComponent(name: 'Dossier:Notes', template: 'components/Dossier/Notes.html.twig')]
final class DossierNotes
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $dossierId = 0;

    #[LiveProp]
    public string $adminPrefix = '';

    #[LiveProp(writable: true)]
    public string $newNote = '';

    #[LiveProp]
    public ?int $editingNoteId = null;

    #[LiveProp(writable: true)]
    public string $editingText = '';

    /** Visible notes; grows via showMoreFeed(). */
    #[LiveProp]
    public int $feedLimit = self::FEED_PAGE;

    /** Visible audit events; grows via showMoreEvents(). */
    #[LiveProp]
    public int $eventsLimit = self::FEED_PAGE;

    /** True while the closure confirmation modal is open. */
    #[LiveProp]
    public bool $confirmingClosure = false;

    /**
     * True while the notes drawer is open. Server-driven on purpose: the
     * drawer lives inside this live component, so a client-only state would
     * be wiped by the morph of every notes action (add, edit, delete).
     */
    #[LiveProp]
    public bool $notesOpen = false;

    private const FEED_PAGE = 5;

    public function __construct(
        private readonly DossierNoteRepository $notes,
        private readonly DossierRepository $dossiers,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly DossierEventRepository $eventRepository,
        private readonly DossierEventLogger $events,
        private readonly TranslatorInterface $translator,
        private readonly DocumentStorage $storage,
        private readonly DossierNumberGenerator $numbers,
        private readonly ClockInterface $clock,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function getManager(): ?DossierManagerView
    {
        $manager = $this->dossier()->getManager();

        return null !== $manager ? $this->managerView($manager) : null;
    }

    /**
     * @return list<DossierManagerView>
     */
    public function getManagerChoices(): array
    {
        return array_map($this->managerView(...), $this->users->findStaff());
    }

    public function getReference(): string
    {
        return (string) $this->dossier()->getReference();
    }

    public function getSourceContactReference(): ?string
    {
        return $this->dossier()->getSourceContactReference();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->dossier()->getCreatedAt() ?? new \DateTimeImmutable();
    }

    #[LiveAction]
    public function assignManager(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $user = null;
        if (0 !== $id) {
            $user = $this->em->find(User::class, $id);
            if (null === $user || [] === array_intersect(['ROLE_ADMIN', 'ROLE_SECTION_DOSSIERS'], $user->getRoles())) {
                throw new NotFoundHttpException('Assignable user not found.');
            }
        }

        $dossier = $this->dossier();
        $dossier->setManager($user);
        $this->events->log($dossier, null !== $user ? 'manager_assigned' : 'manager_unassigned', [
            'name' => null !== $user ? $this->displayName($user) : '',
        ]);
        $this->em->flush();
    }

    /**
     * Manual notes thread (comments), newest first, capped at feedLimit.
     *
     * @return list<array{note: DossierNoteView, canEdit: bool, canDelete: bool}>
     */
    public function getFeed(): array
    {
        return \array_slice($this->noteRows(), 0, $this->feedLimit);
    }

    public function getHiddenFeedCount(): int
    {
        return max(0, \count($this->noteRows()) - $this->feedLimit);
    }

    #[LiveAction]
    public function showMoreFeed(): void
    {
        $this->ensureAdmin();
        $this->feedLimit += 10;
    }

    /**
     * Audit trail (fil de suivi), newest first, capped at eventsLimit.
     *
     * @return list<array{text: string, authorName: string|null, authorAvatar: string|null, createdAt: \DateTimeImmutable}>
     */
    public function getEvents(): array
    {
        return \array_slice($this->eventViews(), 0, $this->eventsLimit);
    }

    public function getHiddenEventsCount(): int
    {
        return max(0, \count($this->eventViews()) - $this->eventsLimit);
    }

    #[LiveAction]
    public function showMoreEvents(): void
    {
        $this->ensureAdmin();
        $this->eventsLimit += 10;
    }

    /**
     * The sibling live components (search editor, persons, modules) log
     * audit events and change the derived status: re-render so the fil de
     * suivi and the status chips pick them up live.
     */
    #[LiveListener('dossier-search:changed')]
    #[LiveListener('dossier-persons:changed')]
    #[LiveListener('dossier-documents:changed')]
    public function refresh(): void
    {
        $this->ensureAdmin();
    }

    /**
     * Manual statuses the operator can pick from.
     *
     * @return list<DossierStatus>
     */
    public function getStatusChoices(): array
    {
        return DossierStatus::manualCases();
    }

    public function getManualStatus(): DossierStatus
    {
        return $this->dossier()->getStatus();
    }

    /** Closure and pending pieces folded in; drives the auto badge. */
    public function getEffectiveStatus(): DossierStatus
    {
        return $this->dossier()->getEffectiveStatus();
    }

    #[LiveAction]
    public function changeStatus(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();

        $status = DossierStatus::tryFrom($value);
        if (null === $status || !$status->isManual()) {
            throw new NotFoundHttpException('Unknown manual dossier status.');
        }

        $dossier = $this->dossier();
        if ($dossier->getStatus() === $status) {
            return;
        }

        $dossier->setStatus($status);
        $this->events->log($dossier, 'status_changed', ['status' => $status->labelKey()]);
        $this->em->flush();
    }

    #[LiveAction]
    public function openNotes(): void
    {
        $this->ensureAdmin();
        $this->notesOpen = true;
    }

    #[LiveAction]
    public function closeNotes(): void
    {
        $this->ensureAdmin();
        $this->notesOpen = false;
    }

    #[LiveAction]
    public function add(): void
    {
        $this->ensureAdmin();

        $text = trim($this->newNote);
        if ('' === $text) {
            return;
        }

        $user = $this->currentUser();
        $this->notes->add(
            $this->dossier(),
            $text,
            (int) $user->getId(),
            $this->displayName($user),
            $user->getAvatarFilename(),
        );
        $this->newNote = '';

        // Clears the leave-guard dirty flag on the front.
        $this->dispatchBrowserEvent('dossier-notes:saved');
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $note = $this->notes->find($id);
        if (null === $note || (int) $note->getDossier()?->getId() !== $this->dossierId) {
            return;
        }
        if (!$this->security->isGranted(DossierNoteVoter::EDIT, $note)) {
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
        if (!$this->security->isGranted(DossierNoteVoter::EDIT, $note)) {
            throw new AccessDeniedException('Only the author or an admin can edit a note.');
        }

        $this->notes->updateText($note, $text);
        $this->editingNoteId = null;
        $this->editingText = '';

        $this->dispatchBrowserEvent('dossier-notes:saved');
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->dossier()->getClosedAt();
    }

    /** Opens the closure confirmation modal. */
    #[LiveAction]
    public function askClose(): void
    {
        $this->ensureAdmin();
        if (!$this->dossier()->isClosed()) {
            $this->confirmingClosure = true;
        }
    }

    #[LiveAction]
    public function cancelClose(): void
    {
        $this->ensureAdmin();
        $this->confirmingClosure = false;
    }

    /**
     * Closes the dossier: every deposited file is purged from disk and
     * database (the deposit page's retention promise), the pairing code is
     * rotated so every emailed link dies, and the public deposit page
     * refuses the dossier from now on.
     */
    #[LiveAction]
    public function confirmClose(): void
    {
        $this->ensureAdmin();
        $this->confirmingClosure = false;
        $dossier = $this->dossier();
        if ($dossier->isClosed()) {
            return;
        }

        $purged = 0;
        foreach ($dossier->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                foreach ($document->getFiles()->toArray() as $file) {
                    $this->storage->delete($dossier, $file);
                    $document->removeFile($file);
                    ++$purged;
                }
            }
        }

        $dossier->setClosedAt($this->clock->now());
        $dossier->setPairingCode($this->numbers->pairingCode());
        $this->events->log($dossier, 'dossier_closed', ['count' => $purged]);
        $this->em->flush();
    }

    /** Reopens the dossier (the rotated pairing code stays the new one). */
    #[LiveAction]
    public function reopen(): void
    {
        $this->ensureAdmin();
        $dossier = $this->dossier();
        if (!$dossier->isClosed()) {
            return;
        }

        $dossier->setClosedAt(null);
        $this->events->log($dossier, 'dossier_reopened');
        $this->em->flush();
    }

    /**
     * Direct deletion from the "…" menu of a note (fade-exit animation on
     * the client), same gesture as the contact notes drawer.
     */
    #[LiveAction]
    public function delete(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $note = $this->notes->find($id);
        if (null === $note || (int) $note->getDossier()?->getId() !== $this->dossierId) {
            return;
        }
        if (!$this->security->isGranted(DossierNoteVoter::DELETE, $note)) {
            throw new AccessDeniedException('Only the author or an admin can delete a note.');
        }

        $this->notes->remove($note);
        if ($this->editingNoteId === $id) {
            $this->editingNoteId = null;
            $this->editingText = '';
        }
    }

    /**
     * Manual notes only, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function noteRows(): array
    {
        $rows = array_map(
            fn (DossierNoteView $note): array => [
                'note' => $note,
                'canEdit' => $this->security->isGranted(DossierNoteVoter::EDIT, $note),
                'canDelete' => $this->security->isGranted(DossierNoteVoter::DELETE, $note),
                'createdAt' => $note->createdAt,
            ],
            $this->notes->listForDossier($this->dossierId),
        );
        usort($rows, static fn (array $a, array $b): int => $b['createdAt'] <=> $a['createdAt']);

        return $rows;
    }

    /**
     * Audit events only, newest first.
     *
     * @return list<array{text: string, authorName: string|null, authorAvatar: string|null, createdAt: \DateTimeImmutable}>
     */
    private function eventViews(): array
    {
        $rows = array_map(
            $this->eventView(...),
            $this->eventRepository->listForDossier($this->dossierId),
        );
        usort($rows, static fn (array $a, array $b): int => $b['createdAt'] <=> $a['createdAt']);

        return $rows;
    }

    /**
     * @return array{text: string, authorName: string|null, authorAvatar: string|null, createdAt: \DateTimeImmutable}
     */
    private function eventView(DossierEvent $event): array
    {
        $payload = $event->getPayload();
        $piece = isset($payload['piece']) ? $this->translator->trans((string) $payload['piece']) : '';
        $params = [
            '%piece%' => $piece,
            '%field%' => isset($payload['field']) ? $this->translator->trans((string) $payload['field']) : '',
            '%value%' => $this->searchValueLabel((string) ($payload['field'] ?? ''), (string) ($payload['value'] ?? '')),
            '%old%' => (string) ($payload['old'] ?? ''),
            '%count%' => (string) ($payload['count'] ?? ''),
            '%tenant%' => (string) ($payload['tenant'] ?? ''),
            '%name%' => (string) ($payload['name'] ?? ''),
            '%recipient%' => (string) ($payload['recipient'] ?? ''),
            '%file%' => (string) ($payload['file'] ?? ''),
            '%reason%' => (string) ($payload['reason'] ?? ''),
            '%description%' => (string) ($payload['description'] ?? ''),
            '%role%' => isset($payload['role']) ? $this->translator->trans((string) $payload['role']) : '',
            '%status%' => isset($payload['status']) ? $this->translator->trans((string) $payload['status']) : '',
            '%pieces%' => implode(', ', array_map(
                fn (string $key): string => $this->translator->trans($key),
                (array) ($payload['pieces'] ?? []),
            )),
        ];

        $kind = $event->getKind();
        // A refusal without reason and a cleared description read differently.
        $key = match (true) {
            'document_refused' === $kind && '' === $params['%reason%'] => 'documentRefusedNoReason',
            'description_updated' === $kind && '' === $params['%description%'] => 'descriptionCleared',
            'search_updated' === $kind && '' === $params['%value%'] => 'searchCleared',
            default => lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $kind)))),
        };

        return [
            'text' => $this->translator->trans('admin.dossiers.show.events.'.$key, $params),
            'authorName' => $event->getAuthorName(),
            'authorAvatar' => $event->getAuthorAvatar(),
            'createdAt' => $event->getCreatedAt(),
        ];
    }

    /**
     * Label prefixes of the search fields whose stored values are internal
     * codes: the audit entry shows the same translated labels as the chips.
     */
    private const SEARCH_VALUE_LABELS = [
        'stayDuration' => 'admin.contacts.project.stayDuration.choice.',
        'furnishing' => 'admin.contacts.project.furnishing.choice.',
        'guarantorType' => 'admin.contacts.project.guarantor.choice.',
        'guarantorStatus' => 'admin.dossiers.show.search.guarantorStatus.choice.',
        'propertyType' => 'listProperty.form.propertyType.choice.',
        'equipment' => 'listProperty.form.amenities.choice.',
        'pets' => 'admin.dossiers.show.search.pets.choice.',
        'earlyMoveIn' => 'admin.dossiers.show.search.earlyMoveIn.choice.',
        'householdType' => 'admin.dossiers.show.search.householdType.choice.',
        'leaseTypes' => 'admin.dossiers.show.search.leaseType.choice.',
        'elevator' => 'admin.dossiers.show.search.elevator.choice.',
        'groundFloor' => 'admin.dossiers.show.search.groundFloor.choice.',
        'topFloor' => 'admin.dossiers.show.search.topFloor.choice.',
        'parking' => 'admin.dossiers.show.search.parking.choice.',
    ];

    /**
     * Translates a search_updated value: each CSV part goes through the
     * field's chip catalogue; free values (dates, amounts, areas) and
     * unknown codes pass through untouched.
     */
    private function searchValueLabel(string $fieldKey, string $value): string
    {
        if ('' === $value) {
            return '';
        }
        $field = str_contains($fieldKey, '.') ? substr($fieldKey, (int) strrpos($fieldKey, '.') + 1) : $fieldKey;
        $prefix = self::SEARCH_VALUE_LABELS[$field] ?? null;
        if (null === $prefix) {
            return $value;
        }

        $parts = array_map(trim(...), explode(',', $value));
        $labels = array_map(function (string $part) use ($prefix): string {
            $label = $this->translator->trans($prefix.$part);

            return $label !== $prefix.$part ? $label : $part;
        }, $parts);

        return implode(', ', $labels);
    }

    private function managerView(User $user): DossierManagerView
    {
        return new DossierManagerView(
            id: (int) $user->getId(),
            fullName: $this->displayName($user),
            email: (string) $user->getEmail(),
            avatarFilename: $user->getAvatarFilename(),
        );
    }

    private function dossier(): Dossier
    {
        return $this->dossiers->find($this->dossierId)
            ?? throw new NotFoundHttpException('Dossier not found.');
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
        if (!$this->security->isGranted('ROLE_SECTION_DOSSIERS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
