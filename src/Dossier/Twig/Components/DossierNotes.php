<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Auth\Entity\User;
use App\Auth\Repository\UserRepository;
use App\Dossier\Domain\DossierManagerView;
use App\Dossier\Domain\DossierNoteView;
use App\Dossier\Entity\Dossier;
use App\Dossier\Repository\DossierNoteRepository;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Security\DossierNoteVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
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

    /** Visible entries; grows via showMoreFeed(). */
    #[LiveProp]
    public int $feedLimit = self::FEED_PAGE;

    private const FEED_PAGE = 5;

    public function __construct(
        private readonly DossierNoteRepository $notes,
        private readonly DossierRepository $dossiers,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
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
            if (null === $user || [] === array_intersect(['ROLE_ADMIN', 'ROLE_EDITOR'], $user->getRoles())) {
                throw new NotFoundHttpException('Assignable user not found.');
            }
        }

        $this->dossier()->setManager($user);
        $this->em->flush();
    }

    /**
     * Notes thread, newest first, capped at feedLimit entries.
     *
     * @return list<array{note: DossierNoteView, canEdit: bool, canDelete: bool}>
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
     * @return list<array{note: DossierNoteView, canEdit: bool, canDelete: bool}>
     */
    private function fullFeed(): array
    {
        return array_map(
            fn (DossierNoteView $note): array => [
                'note' => $note,
                'canEdit' => $this->security->isGranted(DossierNoteVoter::EDIT, $note),
                'canDelete' => $this->security->isGranted(DossierNoteVoter::DELETE, $note),
            ],
            $this->notes->listForDossier($this->dossierId),
        );
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
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
