<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Admin\Domain\UserListItem;
use App\Admin\Repository\AdminUserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Paginated admin user list. The component is rendered behind the admin
 * firewall in the parent template, but its LiveActions are reachable on
 * the public /_components/... route, so we re-check ROLE_ADMIN on every
 * mount and action call.
 */
#[AsLiveComponent(name: 'Admin:UserList', template: 'components/Admin/UserList.html.twig')]
final class UserList
{
    use DefaultActionTrait;

    private const PER_PAGE = 20;

    #[LiveProp]
    public int $page = 1;

    /**
     * Admin URL prefix, propagated through LiveComponent rerenders so the
     * component can build links to the user profile route. Marked
     * writable on mount only to keep clients from pinning a different prefix.
     */
    #[LiveProp]
    public string $adminPrefix = '';

    /** @var list<UserListItem>|null */
    private ?array $itemsCache = null;
    private ?int $totalCache = null;

    public function __construct(
        private readonly AdminUserRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[LiveAction]
    public function more(): void
    {
        $this->ensureAdmin();
        ++$this->page;
    }

    /**
     * @return list<UserListItem>
     */
    public function getItems(): array
    {
        if (null !== $this->itemsCache) {
            return $this->itemsCache;
        }

        return $this->itemsCache = $this->repository->listFirst($this->page * self::PER_PAGE);
    }

    public function hasMore(): bool
    {
        return $this->getTotalCount() > $this->page * self::PER_PAGE;
    }

    public function getTotalCount(): int
    {
        return $this->totalCache ??= $this->repository->count();
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
