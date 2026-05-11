<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Admin\Domain\DocumentRequestSummary;
use App\Admin\Repository\DocumentRequestRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Paginated, infinite-scroll list of recent DocumentRequests on the documents
 * hub. Renders behind the admin firewall in the parent template, but
 * LiveActions are reachable on the public /_components/... route — so we
 * re-check ROLE_ADMIN on every mount and action call.
 *
 * Pagination is SQL-side (LIMIT/OFFSET via the repository): each page
 * triggers one query for the current slice and one for the total count.
 * Total count is memoised per render so hasMore() and getTotalCount() share
 * a single query.
 */
#[AsLiveComponent(name: 'Admin:DocumentRequestList', template: 'components/Admin/DocumentRequestList.html.twig')]
final class DocumentRequestList
{
    use DefaultActionTrait;

    private const PER_PAGE = 25;

    #[LiveProp]
    public int $page = 1;

    /**
     * Needed to build PDF download links via path(). Kept as a LiveProp so it
     * survives re-renders; the hub controller already validated it against
     * %admin_path_prefix% before mounting, so we trust the value here.
     */
    #[LiveProp]
    public string $adminPrefix = '';

    private ?int $totalCountCache = null;

    public function __construct(
        private readonly DocumentRequestRepository $repository,
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
     * @return list<DocumentRequestSummary>
     */
    public function getItems(): array
    {
        return $this->repository->findSummariesPage($this->page * self::PER_PAGE);
    }

    public function hasMore(): bool
    {
        return $this->getTotalCount() > $this->page * self::PER_PAGE;
    }

    public function getTotalCount(): int
    {
        return $this->totalCountCache ??= $this->repository->countTotal();
    }

    public function isEmpty(): bool
    {
        return 0 === $this->getTotalCount();
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
