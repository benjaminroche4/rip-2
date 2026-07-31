<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Contact\Domain\ContactListItem;
use App\Contact\Domain\RecontactChannel;
use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Editable lead-quality block on the contact detail page: clicking a star
 * persists the rating immediately, the qualification note is saved via its
 * own action. ROLE_ADMIN re-checked on every entry point.
 */
#[AsLiveComponent(name: 'Admin:LeadQuality', template: 'components/Admin/LeadQuality.html.twig')]
final class LeadQuality
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $contactId = 0;

    #[LiveProp(writable: true)]
    public string $note = '';

    /** Note UI mode: read view with a pencil, or textarea + save button. */
    #[LiveProp]
    public bool $editingNote = false;

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(int $contactId): void
    {
        $this->ensureAdmin();
        $this->contactId = $contactId;
        $this->note = $this->getContact()?->leadNote ?? '';
        $this->editingNote = '' === $this->note;
    }

    public function getContact(): ?ContactListItem
    {
        return $this->repository->listByIds([$this->contactId])[0] ?? null;
    }

    #[LiveAction]
    public function rate(#[LiveArg] int $rating): void
    {
        $this->ensureAdmin();

        if ($rating < 1 || $rating > 5) {
            throw new BadRequestHttpException('Lead rating must be between 1 and 5.');
        }

        $this->repository->saveLeadRating($this->contactId, $rating);

        // The detail header (status control) shows the rating badge live.
        $this->emit('contacts:changed');
    }

    #[LiveAction]
    public function saveNote(): void
    {
        $this->ensureAdmin();

        $this->repository->saveLeadNote($this->contactId, $this->note);
        $this->note = trim($this->note);
        // Back to read mode once something is saved; an emptied note keeps
        // the field open.
        $this->editingNote = '' === $this->note;

        // Clears the leave-guard dirty flag on the front, and lets a host
        // component (the qualify modal on the list) close itself.
        $this->dispatchBrowserEvent('lead-quality:saved');
        $this->emit('lead-quality:saved');
    }

    /**
     * @return list<RecontactChannel>
     */
    public function getChannels(): array
    {
        return RecontactChannel::cases();
    }

    #[LiveAction]
    public function setChannel(#[LiveArg] string $channel): void
    {
        $this->ensureAdmin();

        $case = RecontactChannel::tryFrom($channel)
            ?? throw new BadRequestHttpException(\sprintf('Unknown recontact channel "%s".', $channel));

        $this->repository->saveRecontactChannel($this->contactId, $case);
    }

    #[LiveAction]
    public function startEditingNote(): void
    {
        $this->ensureAdmin();
        $this->editingNote = true;
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
