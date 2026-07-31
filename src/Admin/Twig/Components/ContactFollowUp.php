<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Repository\UserRepository;
use App\Contact\Domain\ContactListItem;
use App\Contact\Domain\ContactEventItem;
use App\Contact\Repository\ContactEventRepository;
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
 * Follow-up card on the contact detail page: first treatment / last change
 * dates, plus the editable planned recall date. Live so it refreshes when
 * the status control emits "contacts:changed".
 */
#[AsLiveComponent(name: 'Admin:ContactFollowUp', template: 'components/Admin/ContactFollowUp.html.twig')]
final class ContactFollowUp
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $contactId = 0;

    /** Planned recall, "Y-m-dTH:i" (datetime-local input format), '' = none. */
    #[LiveProp(writable: true)]
    public string $recallAt = '';

    /** Timeline collapsed to the most recent entries by default. */
    #[LiveProp]
    public bool $showAllTimeline = false;

    private const TIMELINE_RECENT = 5;

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly ContactEventRepository $events,
        private readonly UserRepository $users,
        private readonly Security $security,
    ) {
    }

    public function mount(int $contactId): void
    {
        $this->ensureAdmin();
        $this->contactId = $contactId;
        $this->recallAt = $this->getContact()?->recallAt?->format('Y-m-d\TH:i') ?? '';
    }

    #[LiveListener('contacts:changed')]
    public function refresh(): void
    {
        // Re-render is the whole point; dates are re-read in the template.
        $this->ensureAdmin();
    }

    public function getContact(): ?ContactListItem
    {
        return $this->repository->listByIds([$this->contactId])[0] ?? null;
    }

    /**
     * History in chronological order (status changes, motifs, recap
     * emails), rendered as the timeline after the "received" entry.
     * Collapsed to the last entries unless expanded.
     *
     * @return list<ContactEventItem>
     */
    public function getTimelineEvents(): array
    {
        $events = array_reverse($this->events->listForContact($this->contactId));

        return $this->showAllTimeline ? $events : \array_slice($events, -self::TIMELINE_RECENT);
    }

    public function getHiddenTimelineCount(): int
    {
        return $this->showAllTimeline
            ? 0
            : max(0, \count($this->events->listForContact($this->contactId)) - self::TIMELINE_RECENT);
    }

    #[LiveAction]
    public function toggleTimeline(): void
    {
        $this->ensureAdmin();
        $this->showAllTimeline = !$this->showAllTimeline;
    }

    #[LiveAction]
    public function saveRecall(): void
    {
        $this->ensureAdmin();

        $recallAt = null;
        if ('' !== trim($this->recallAt)) {
            $recallAt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', trim($this->recallAt)) ?: null;
            if (null !== $recallAt && $recallAt < new \DateTimeImmutable()) {
                // A recall can only be planned in the future.
                $recallAt = null;
            }
            if (null === $recallAt) {
                // Unparseable or past input from a hand-edited request: ignore it.
                $this->recallAt = '';

                return;
            }
        }

        $this->repository->saveRecallAt($this->contactId, $recallAt);
        $this->recallAt = $recallAt?->format('Y-m-d\TH:i') ?? '';

        // Refreshes the recall chip in the detail header.
        $this->emit('contacts:changed');
    }

    #[LiveAction]
    public function clearRecall(): void
    {
        $this->ensureAdmin();
        $this->repository->saveRecallAt($this->contactId, null);
        $this->recallAt = '';

        $this->emit('contacts:changed');
    }

    /**
     * Assignable team members, as scalar rows (never entities in templates).
     *
     * @return list<array{id: int, name: string, avatar: ?string}>
     */
    public function getAssignees(): array
    {
        return array_map(
            static fn ($user): array => [
                'id' => (int) $user->getId(),
                'name' => trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? '')) ?: (string) $user->getEmail(),
                'avatar' => $user->getAvatarFilename(),
            ],
            $this->users->findAdmins(),
        );
    }

    #[LiveAction]
    public function assign(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $user = 0 !== $id ? $this->users->find($id) : null;
        if (0 !== $id && null === $user) {
            throw new BadRequestHttpException('Unknown assignee.');
        }

        $this->repository->assign($this->contactId, $user);
        $this->emit('contacts:changed');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
