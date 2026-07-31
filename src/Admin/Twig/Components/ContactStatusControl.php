<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ClosureReason;
use App\Contact\Domain\ContactListItem;
use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\NextStep;
use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Status pill + dropdown for the contact detail page. Same rules as the
 * list: ROLE_ADMIN on every entry point, author/date snapshot recorded on
 * change, sidebar badge notified via the shared "contacts:changed" event.
 */
#[AsLiveComponent(name: 'Admin:ContactStatusControl', template: 'components/Admin/ContactStatusControl.html.twig')]
final class ContactStatusControl
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $contactId = 0;

    public function __construct(
        private readonly ContactRepository $repository,
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
        // Re-render only: picks up a lead rating set by the LeadQuality
        // component on the same page.
        $this->ensureAdmin();
    }

    public function getContact(): ?ContactListItem
    {
        return $this->repository->listByIds([$this->contactId])[0] ?? null;
    }

    /**
     * @return list<ContactStatus>
     */
    public function getStatuses(): array
    {
        return ContactStatus::cases();
    }

    /**
     * @return list<ClosureReason>
     */
    public function getClosureReasons(): array
    {
        return ClosureReason::cases();
    }

    /**
     * @return list<NextStep>
     */
    public function getNextSteps(): array
    {
        return NextStep::cases();
    }

    #[LiveAction]
    public function setClosureReason(#[LiveArg] string $reason): void
    {
        $this->ensureAdmin();

        $case = ClosureReason::tryFrom($reason)
            ?? throw new BadRequestHttpException(\sprintf('Unknown closure reason "%s".', $reason));

        [$fullName, $avatar] = $this->authorSnapshot();
        $this->repository->saveClosureReason($this->contactId, $case, $fullName, $avatar);
        // The terminal banner and the follow-up thread show the reason live.
        $this->emit('contacts:changed');
    }

    #[LiveAction]
    public function setNextStep(#[LiveArg] string $step): void
    {
        $this->ensureAdmin();

        $case = NextStep::tryFrom($step)
            ?? throw new BadRequestHttpException(\sprintf('Unknown next step "%s".', $step));

        // Clicking the selected chip clears it.
        $contact = $this->getContact();
        $this->repository->saveNextStep($this->contactId, $contact?->nextStep === $case ? null : $case);
        $this->emit('contacts:changed');
    }

    #[LiveAction]
    public function change(#[LiveArg] string $status): void
    {
        $this->ensureAdmin();

        $newStatus = ContactStatus::tryFrom($status)
            ?? throw new BadRequestHttpException(\sprintf('Unknown contact status "%s".', $status));

        [$fullName, $avatar] = $this->authorSnapshot();
        $this->repository->updateStatus($this->contactId, $newStatus, $fullName, $avatar);
        $this->emit('contacts:changed');
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function authorSnapshot(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [null, null];
        }

        return [
            trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? '')) ?: $user->getEmail(),
            $user->getAvatarFilename(),
        ];
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
