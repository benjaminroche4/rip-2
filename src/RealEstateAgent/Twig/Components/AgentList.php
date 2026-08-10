<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use App\RealEstateAgent\Domain\AgentSummary;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Real-estate agents directory: alphabetical list with a debounced
 * free-text search over name, agency, email and phone.
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgentList', template: 'components/RealEstateAgent/AgentList.html.twig')]
final class AgentList
{
    use DefaultActionTrait;

    /** Secret admin URL prefix, needed to build links inside the component. */
    #[LiveProp]
    public string $adminPrefix = '';

    /** Free-text search. Debounced client-side, mirrored in the URL. */
    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    /** @var list<AgentSummary>|null */
    private ?array $summariesCache = null;

    public function __construct(
        private readonly RealEstateAgentRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function getTotalCount(): int
    {
        return \count($this->summaries());
    }

    /**
     * @return list<AgentSummary>
     */
    public function getAgents(): array
    {
        $rows = $this->summaries();

        $needle = trim($this->search);
        if ('' !== $needle) {
            $rows = array_values(array_filter(
                $rows,
                static fn (AgentSummary $agent): bool => false !== mb_stripos($agent->fullName(), $needle)
                    || (null !== $agent->agency && false !== mb_stripos($agent->agency, $needle))
                    || (null !== $agent->email && false !== mb_stripos($agent->email, $needle))
                    || (null !== $agent->phone && false !== mb_stripos($agent->phone, $needle)),
            ));
        }

        return $rows;
    }

    /**
     * @return list<AgentSummary>
     */
    private function summaries(): array
    {
        return $this->summariesCache ??= $this->repository->findSummaries();
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_AGENTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
