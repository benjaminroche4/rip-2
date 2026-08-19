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

    /**
     * Filtre "statut post-visite" ('' = tout). Writable + url : une valeur
     * inconnue arrivée par l'URL est neutralisée à la lecture
     * (getActivePostStatus), jamais passée telle quelle à la requête.
     */
    #[LiveProp(writable: true, url: true)]
    public string $postStatus = '';

    private const POST_STATUSES = ['report_due', 'thinking', 'positioning', 'refused', 'accepted', 'outcome_refused'];

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

    /** Chips de filtre post-visite : toggle-off sur la chip active. */
    #[LiveAction]
    public function choosePostStatus(#[\Symfony\UX\LiveComponent\Attribute\LiveArg] string $value): void
    {
        $this->ensureAdmin();
        if (!\in_array($value, self::POST_STATUSES, true)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Unknown post status.');
        }
        $this->postStatus = $this->postStatus === $value ? '' : $value;
        $this->limit = self::PAGE_SIZE;
    }

    /** Filtre effectif : une valeur inconnue venue de l'URL ne filtre rien. */
    public function getActivePostStatus(): ?string
    {
        return \in_array($this->postStatus, self::POST_STATUSES, true) ? $this->postStatus : null;
    }

    /**
     * Chips proposées, avec le vocabulaire des badges des cards.
     *
     * @return list<array{value: string, labelKey: string, icon: string}>
     */
    public function getPostStatusFilters(): array
    {
        return [
            ['value' => 'report_due', 'labelKey' => 'admin.visits.row.reportDue', 'icon' => 'lucide:clipboard-pen'],
            ['value' => 'thinking', 'labelKey' => \App\Visit\Domain\ClientDecision::Thinking->labelKey(), 'icon' => \App\Visit\Domain\ClientDecision::Thinking->icon()],
            ['value' => 'positioning', 'labelKey' => \App\Visit\Domain\ClientDecision::Positioning->labelKey(), 'icon' => \App\Visit\Domain\ClientDecision::Positioning->icon()],
            ['value' => 'refused', 'labelKey' => \App\Visit\Domain\ClientDecision::Refused->labelKey(), 'icon' => \App\Visit\Domain\ClientDecision::Refused->icon()],
            ['value' => 'accepted', 'labelKey' => 'admin.visits.archive.filter.accepted', 'icon' => 'lucide:badge-check'],
            ['value' => 'outcome_refused', 'labelKey' => 'admin.visits.archive.filter.outcomeRefused', 'icon' => 'lucide:badge-x'],
        ];
    }

    /**
     * Shown window, grouped by day [Y-m-d => visits], most recent first.
     *
     * @return array<string, list<VisitSummary>>
     */
    public function getVisitsByDay(): array
    {
        $groups = [];
        foreach ($this->repository->findArchivedSummaries($this->today(), $this->limit, $this->getActivePostStatus()) as $visit) {
            $groups[$visit->scheduledAt->format('Y-m-d')][] = $visit;
        }

        return $groups;
    }

    public function getTotalCount(): int
    {
        return $this->repository->countArchived($this->today(), $this->getActivePostStatus());
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
