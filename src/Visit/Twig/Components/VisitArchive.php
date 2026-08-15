<?php

declare(strict_types=1);

namespace App\Visit\Twig\Components;

use App\Visit\Domain\VisitSummary;
use App\Visit\Repository\VisitRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Visits archive: every day before today, most recent first, grouped by
 * day. Days land here by themselves at midnight (a visit "archives" simply
 * by being in the past). Paginated with a "see more" button since the list
 * only ever grows.
 */
#[AsLiveComponent(name: 'Visit:VisitArchive', template: 'components/Visit/VisitArchive.html.twig')]
final class VisitArchive
{
    use VisitsSectionGuard;
    use DefaultActionTrait;

    private const TIMEZONE = 'Europe/Paris';
    private const PAGE_SIZE = 25;

    /** Secret admin URL prefix, needed to build links inside the component. */
    #[LiveProp]
    public string $adminPrefix = '';

    /** How many visits are shown; "see more" widens the window. */
    #[LiveProp]
    public int $limit = self::PAGE_SIZE;

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->limit = max(1, min($this->limit, 500));
    }

    public function __construct(
        private readonly VisitRepository $repository,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    #[LiveAction]
    public function loadMore(): void
    {
        $this->ensureAdmin();
        $this->limit += self::PAGE_SIZE;
    }

    /**
     * Shown window, grouped by day [Y-m-d => visits], most recent first.
     *
     * @return array<string, list<VisitSummary>>
     */
    public function getVisitsByDay(): array
    {
        $groups = [];
        foreach ($this->repository->findArchivedSummaries($this->today(), $this->limit) as $visit) {
            $groups[$visit->scheduledAt->format('Y-m-d')][] = $visit;
        }

        return $groups;
    }

    public function getTotalCount(): int
    {
        return $this->repository->countArchived($this->today());
    }

    public function hasMore(): bool
    {
        return $this->getTotalCount() > $this->limit;
    }

    public function getShownCount(): int
    {
        return min($this->limit, $this->getTotalCount());
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE));
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_VISITS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
