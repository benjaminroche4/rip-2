<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use App\RealEstateAgent\Domain\AgencySummary;
use App\RealEstateAgent\Domain\AgentSummary;
use App\RealEstateAgent\Repository\AgencyRepository;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Real-estate agents directory: an alphabetical list with a debounced
 * free-text search, and a toggle to switch between agents and their
 * agencies (each agency showing how many agents belong to it).
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgentList', template: 'components/RealEstateAgent/AgentList.html.twig')]
final class AgentList
{
    use AgentsSectionGuard;
    use DefaultActionTrait;

    private const VIEWS = ['agents', 'agencies'];

    /** Secret admin URL prefix, needed to build links inside the component. */
    #[LiveProp]
    public string $adminPrefix = '';

    /** Free-text search. Debounced client-side, mirrored in the URL. */
    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    /** Active view: 'agents' (default) or 'agencies'. Mirrored in the URL. */
    #[LiveProp(writable: true, url: true)]
    public string $view = 'agents';

    /** @var list<AgentSummary>|null */
    private ?array $agentCache = null;

    /** @var list<AgencySummary>|null */
    private ?array $agencyCache = null;

    public function __construct(
        private readonly RealEstateAgentRepository $repository,
        private readonly AgencyRepository $agencies,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[LiveAction]
    public function chooseView(#[LiveArg] string $view): void
    {
        $this->ensureAdmin();
        if (!\in_array($view, self::VIEWS, true)) {
            throw new NotFoundHttpException('Unknown view.');
        }
        $this->view = $view;
    }

    public function isAgenciesView(): bool
    {
        return 'agencies' === $this->view;
    }

    /** Total agents in the directory (unfiltered), for the toggle label. */
    public function getAgentTotal(): int
    {
        return \count($this->agentSummaries());
    }

    /** Total agencies in the directory (unfiltered), for the toggle label. */
    public function getAgencyTotal(): int
    {
        return \count($this->agencySummaries());
    }

    /** Rows in the active view after the search filter, for the count line. */
    public function getTotalCount(): int
    {
        return $this->isAgenciesView() ? \count($this->getAgencies()) : \count($this->getAgents());
    }

    /**
     * @return list<AgentSummary>
     */
    public function getAgents(): array
    {
        $needle = trim($this->search);
        if ('' === $needle) {
            return $this->agentSummaries();
        }

        return array_values(array_filter(
            $this->agentSummaries(),
            static fn (AgentSummary $agent): bool => false !== mb_stripos($agent->fullName(), $needle)
                || (null !== $agent->agency && false !== mb_stripos($agent->agency, $needle))
                || (null !== $agent->email && false !== mb_stripos($agent->email, $needle))
                || (null !== $agent->phone && false !== mb_stripos($agent->phone, $needle)),
        ));
    }

    /**
     * @return list<AgencySummary>
     */
    public function getAgencies(): array
    {
        $needle = trim($this->search);
        if ('' === $needle) {
            return $this->agencySummaries();
        }

        return array_values(array_filter(
            $this->agencySummaries(),
            static fn (AgencySummary $agency): bool => false !== mb_stripos($agency->name, $needle)
                || (null !== $agency->brand && false !== mb_stripos($agency->brand, $needle)),
        ));
    }

    /**
     * @return list<AgentSummary>
     */
    private function agentSummaries(): array
    {
        return $this->agentCache ??= $this->repository->findSummaries();
    }

    /**
     * @return list<AgencySummary>
     */
    private function agencySummaries(): array
    {
        return $this->agencyCache ??= $this->agencies->findSummaries();
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_AGENTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
