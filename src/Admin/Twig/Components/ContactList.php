<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Contact\Domain\ContactListItem;
use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
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
    use DefaultActionTrait;

    private const PER_PAGE = 10;

    #[LiveProp]
    public int $page = 1;

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
    }

    #[LiveAction]
    public function more(): void
    {
        $this->ensureAdmin();
        ++$this->page;
    }

    /**
     * @return list<ContactListItem>
     */
    public function getItems(): array
    {
        return $this->itemsCache ??= $this->repository->listFirst($this->page * self::PER_PAGE);
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
