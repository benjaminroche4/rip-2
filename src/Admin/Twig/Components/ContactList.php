<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactListItem;
use App\Contact\Domain\ContactStatus;
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
 * Paginated admin contact submissions list. Same security model as
 * Admin:UserList: the component renders behind the admin firewall, but its
 * LiveActions are reachable on the public /_components/... route, so we
 * re-check ROLE_ADMIN on every mount and action call.
 */
#[AsLiveComponent(name: 'Admin:ContactList', template: 'components/Admin/ContactList.html.twig')]
final class ContactList
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    private const PER_PAGE = 10;

    #[LiveProp]
    public int $page = 1;

    /** Secret admin URL prefix, needed to build links inside the component. */
    #[LiveProp]
    public string $adminPrefix = '';

    /**
     * "all" or a ContactStatus backed value. Mirrored in the URL.
     * Defaults to the untreated inbox: the page opens on what needs action.
     */
    #[LiveProp(url: true)]
    public string $statusFilter = 'new';

    /** 'all' = every request, 'mine' = only the ones assigned to me. */
    #[LiveProp(url: true)]
    public string $scope = 'all';

    /** 'recent' | 'recall' | 'rating' | 'budget'. Mirrored in the URL. */
    #[LiveProp(url: true)]
    public string $sort = 'recent';

    public const SORTS = ['recent', 'recall', 'rating', 'budget'];

    /** Free-text search over name, email and reference. Debounced client-side. */
    #[LiveProp(writable: true, url: true, onUpdated: 'onSearchUpdated')]
    public string $search = '';

    /**
     * Card that just left the current filter via a status change. It stays
     * in the rendered list for one more pass so the front can animate its
     * removal instead of dropping it abruptly.
     */
    #[LiveProp]
    public ?int $leavingContactId = null;

    /**
     * Card whose status was just changed: its next render plays the
     * confirmation feedback (pill pop + transient "updated" chip).
     */
    #[LiveProp]
    public ?int $confirmedContactId = null;

    /**
     * Ids in their on-screen order, captured when the list is (re)built.
     * Later live re-renders reuse it so cards don't jump around while the
     * admin is interacting; a page reload, filter or "load more" re-sorts.
     *
     * @var list<int>
     */
    #[LiveProp]
    public array $pinnedOrder = [];

    /** @var list<ContactListItem>|null */
    private ?array $itemsCache = null;
    private ?int $totalCache = null;

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();

        // The filter can arrive from a hand-edited URL — fall back to "all"
        // rather than 500 on an unknown value.
        if ('all' !== $this->statusFilter && null === ContactStatus::tryFrom($this->statusFilter)) {
            $this->statusFilter = 'all';
        }
        if (!\in_array($this->scope, ['all', 'mine'], true)) {
            $this->scope = 'all';
        }
        if (!\in_array($this->sort, self::SORTS, true)) {
            $this->sort = 'recent';
        }

        // Pin the order NOW: live props are dehydrated before the template
        // body runs, so populating pinnedOrder during render never persists.
        $this->getItems();
    }

    #[LiveAction]
    public function more(): void
    {
        $this->ensureAdmin();
        ++$this->page;
        $this->pinnedOrder = [];
        $this->leavingContactId = null;
        $this->confirmedContactId = null;
        $this->getItems();
    }

    #[LiveAction]
    public function changeStatus(#[LiveArg] int $id, #[LiveArg] string $status): void
    {
        $this->ensureAdmin();

        $newStatus = ContactStatus::tryFrom($status)
            ?? throw new BadRequestHttpException(\sprintf('Unknown contact status "%s".', $status));

        $user = $this->security->getUser();
        $avatar = $user instanceof User ? $user->getAvatarFilename() : null;
        $this->repository->updateStatus($id, $newStatus, $this->currentUserName(), $avatar);
        $this->itemsCache = null;
        $this->totalCache = null;

        $filterStatus = $this->currentStatus();
        $this->leavingContactId = null !== $filterStatus && $newStatus !== $filterStatus ? $id : null;
        $this->confirmedContactId = $id;
        $this->getItems();

        // Let the sidebar badge (and any other listener) refresh its count.
        $this->emit('contacts:changed');
    }

    private function currentUserName(): ?string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));

        return '' !== $fullName ? $fullName : $user->getEmail();
    }

    #[LiveAction]
    public function filter(#[LiveArg] string $status): void
    {
        $this->ensureAdmin();

        if ('all' !== $status && null === ContactStatus::tryFrom($status)) {
            throw new BadRequestHttpException(\sprintf('Unknown contact status filter "%s".', $status));
        }

        $this->statusFilter = $status;
        $this->page = 1;
        $this->pinnedOrder = [];
        $this->leavingContactId = null;
        $this->confirmedContactId = null;
        $this->itemsCache = null;
        $this->totalCache = null;
        $this->getItems();
    }

    #[LiveAction]
    public function changeScope(#[LiveArg] string $scope): void
    {
        $this->ensureAdmin();

        if (!\in_array($scope, ['all', 'mine'], true)) {
            return;
        }

        $this->scope = $scope;
        $this->page = 1;
        $this->pinnedOrder = [];
        $this->leavingContactId = null;
        $this->confirmedContactId = null;
        $this->itemsCache = null;
        $this->totalCache = null;
        $this->getItems();
    }

    #[LiveAction]
    public function changeSort(#[LiveArg] string $sort): void
    {
        $this->ensureAdmin();

        if (!\in_array($sort, self::SORTS, true)) {
            return;
        }

        $this->sort = $sort;
        // Sorting by recall/rating/budget only makes sense across every
        // status: widen the rail filter to "all" so the list matches what
        // the selector shows.
        if ('recent' !== $sort) {
            $this->statusFilter = 'all';
        }
        $this->page = 1;
        $this->pinnedOrder = [];
        $this->leavingContactId = null;
        $this->confirmedContactId = null;
        $this->itemsCache = null;
        $this->totalCache = null;
        $this->getItems();
    }

    /**
     * Writable-prop hook: a new search term restarts the list from a fresh
     * page and a fresh pinned order.
     */
    public function onSearchUpdated(mixed $previousValue): void
    {
        // A search spans every status: drop the rail filter so results are
        // not silently truncated to the selected status.
        if ('' !== trim($this->search)) {
            $this->statusFilter = 'all';
        }
        $this->page = 1;
        $this->pinnedOrder = [];
        $this->leavingContactId = null;
        $this->confirmedContactId = null;
        $this->itemsCache = null;
        $this->totalCache = null;
        $this->getItems();
    }

    /**
     * @return list<ContactStatus>
     */
    public function getStatuses(): array
    {
        return ContactStatus::cases();
    }

    /**
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        return $this->repository->countsByStatus($this->assigneeFilter());
    }

    /**
     * @return list<ContactListItem>
     */
    public function getItems(): array
    {
        if (null !== $this->itemsCache) {
            return $this->itemsCache;
        }

        if ([] !== $this->pinnedOrder) {
            // Pinned pass: drop cards that no longer match the filter,
            // except the one currently animating its way out.
            $filterStatus = $this->currentStatus();
            $items = $this->repository->listByIds($this->pinnedOrder);
            if (null !== $filterStatus) {
                $items = array_values(array_filter(
                    $items,
                    fn (ContactListItem $item): bool => $item->status === $filterStatus || $item->id === $this->leavingContactId,
                ));
            }

            return $this->itemsCache = $items;
        }

        $items = $this->repository->listFirst($this->page * self::PER_PAGE, $this->currentStatus(), $this->searchTerm(), $this->assigneeFilter(), $this->sort);
        $this->pinnedOrder = array_map(static fn (ContactListItem $item): int => $item->id, $items);

        return $this->itemsCache = $items;
    }

    public function hasMore(): bool
    {
        return $this->getTotalCount() > $this->page * self::PER_PAGE;
    }

    public function getTotalCount(): int
    {
        return $this->totalCache ??= $this->repository->countFiltered($this->currentStatus(), $this->searchTerm(), $this->assigneeFilter());
    }

    /** Badge on the "my requests" tab: my volume within the current filters. */
    public function getMineCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->repository->countFiltered($this->currentStatus(), $this->searchTerm(), (int) $user->getId());
    }

    private function assigneeFilter(): ?int
    {
        if ('mine' !== $this->scope) {
            return null;
        }

        $user = $this->security->getUser();

        return $user instanceof User ? (int) $user->getId() : null;
    }

    private function currentStatus(): ?ContactStatus
    {
        return 'all' === $this->statusFilter ? null : ContactStatus::tryFrom($this->statusFilter);
    }

    private function searchTerm(): ?string
    {
        $term = trim($this->search);

        return '' !== $term ? $term : null;
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
