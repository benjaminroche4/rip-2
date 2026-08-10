<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierStatus;
use App\Dossier\Domain\DossierSummary;
use App\Dossier\Repository\DossierRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Dossiers list with the same reading grid as the leads page: status filter
 * chips with counts in a sticky sidebar plus a debounced free-text search.
 * Statuses are the effective ones (closure and pending pieces folded in), so
 * filtering happens in PHP on the summaries, not in SQL.
 */
#[AsLiveComponent(name: 'Dossier:DossierList', template: 'components/Dossier/DossierList.html.twig')]
final class DossierList
{
    use DefaultActionTrait;

    /** Secret admin URL prefix, needed to build links inside the component. */
    #[LiveProp]
    public string $adminPrefix = '';

    /** "all" or a DossierStatus backed value. Mirrored in the URL. */
    #[LiveProp(url: true)]
    public string $statusFilter = 'all';

    /** 'all' = every dossier, 'mine' = only the ones I manage. */
    #[LiveProp(url: true)]
    public string $scope = 'all';

    /** Free-text search over name, tenant and reference. Debounced client-side. */
    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    /** @var list<DossierSummary>|null */
    private ?array $summariesCache = null;

    public function __construct(
        private readonly DossierRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();

        // The filter can arrive from a hand-edited URL — fall back to "all"
        // rather than 500 on an unknown value.
        if ('all' !== $this->statusFilter && null === DossierStatus::tryFrom($this->statusFilter)) {
            $this->statusFilter = 'all';
        }
        if (!\in_array($this->scope, ['all', 'mine'], true)) {
            $this->scope = 'all';
        }
    }

    #[LiveAction]
    public function changeScope(#[LiveArg] string $scope): void
    {
        $this->ensureAdmin();
        if (!\in_array($scope, ['all', 'mine'], true)) {
            throw new AccessDeniedException('Unknown dossier scope.');
        }
        $this->scope = $scope;
    }

    public function getMineCount(): int
    {
        return \count($this->scoped('mine'));
    }

    #[LiveAction]
    public function filter(#[LiveArg] string $status): void
    {
        $this->ensureAdmin();
        if ('all' !== $status && null === DossierStatus::tryFrom($status)) {
            throw new AccessDeniedException('Unknown dossier status filter.');
        }
        $this->statusFilter = $status;
    }

    /**
     * @return list<DossierStatus>
     */
    public function getStatuses(): array
    {
        return DossierStatus::cases();
    }

    /**
     * Counts follow the active scope so the sidebar chips always describe
     * the list actually shown.
     *
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        $counts = array_fill_keys(array_column(DossierStatus::cases(), 'value'), 0);
        foreach ($this->scoped($this->scope) as $summary) {
            ++$counts[$summary->status->value];
        }

        return $counts;
    }

    public function getTotalCount(): int
    {
        return \count($this->scoped($this->scope));
    }

    /**
     * @return list<DossierSummary>
     */
    public function getDossiers(): array
    {
        $rows = $this->scoped($this->scope);

        if ('all' !== $this->statusFilter) {
            $rows = array_values(array_filter(
                $rows,
                fn (DossierSummary $summary): bool => $summary->status->value === $this->statusFilter,
            ));
        }

        $needle = trim($this->search);
        if ('' !== $needle) {
            $rows = array_values(array_filter(
                $rows,
                static fn (DossierSummary $summary): bool => false !== mb_stripos($summary->name, $needle)
                    || false !== mb_stripos($summary->reference, $needle)
                    || (null !== $summary->primaryTenantName && false !== mb_stripos($summary->primaryTenantName, $needle)),
            ));
        }

        return $rows;
    }

    /**
     * @return list<DossierSummary>
     */
    private function summaries(): array
    {
        return $this->summariesCache ??= $this->repository->findSummaries();
    }

    /**
     * @return list<DossierSummary>
     */
    private function scoped(string $scope): array
    {
        if ('mine' !== $scope) {
            return $this->summaries();
        }

        $user = $this->security->getUser();
        $userId = $user instanceof User ? (int) $user->getId() : 0;

        return array_values(array_filter(
            $this->summaries(),
            static fn (DossierSummary $summary): bool => $summary->managerId === $userId,
        ));
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_DOSSIERS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
