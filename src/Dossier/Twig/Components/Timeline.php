<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\MoveInTimeline;
use App\Dossier\Entity\Dossier;
use App\Dossier\Repository\DossierRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "Avancement" card on the dossier detail page: progress bar and late-or-not
 * badge between the dossier start and the desired move-in date. Live so it
 * recomputes when the search editor changes the move-in date.
 */
#[AsLiveComponent(name: 'Dossier:Timeline', template: 'components/Dossier/Timeline.html.twig')]
final class Timeline
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $dossierId = 0;

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly ClockInterface $clock,
        private readonly Security $security,
        private readonly \App\Visit\Repository\VisitRepository $visits,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[LiveListener('dossier-search:changed')]
    public function refresh(): void
    {
        // Re-render only: the timeline is recomputed from the fresh search.
        $this->ensureAdmin();
    }

    public function getTimeline(): ?MoveInTimeline
    {
        $dossier = $this->dossier();
        $moveInAt = $dossier->getSearch()?->getMoveInAt();
        if (null === $moveInAt) {
            return null;
        }

        return MoveInTimeline::fromDates(
            $dossier->getCreatedAt() ?? $this->clock->now(),
            $moveInAt,
            $this->clock->now(),
        );
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->dossier()->getCreatedAt() ?? $this->clock->now();
    }

    /**
     * Visit counters of the dossier (cancelled ones excluded), same tiles as
     * on the list card.
     *
     * @return array{upcoming: int, total: int}
     */
    public function getVisitCounts(): array
    {
        $counts = $this->visits->countsByDossier([$this->dossierId], $this->clock->now());

        return $counts[$this->dossierId] ?? ['upcoming' => 0, 'total' => 0];
    }

    public function getMoveInAt(): ?\DateTimeImmutable
    {
        return $this->dossier()->getSearch()?->getMoveInAt();
    }

    /**
     * The four facts that decide whether a dossier can move forward, read at
     * a glance under the timeline: budget, who lives there, which language to
     * write in, and the guarantee (no guarantor, no lease in Paris).
     *
     * @return array{
     *     budget: int|null,
     *     maxAffordable: int|null,
     *     overBudget: bool,
     *     tenants: int,
     *     household: string|null,
     *     language: string|null,
     *     guarantor: string|null,
     *     guarantorStatus: string|null,
     * }
     */
    public function getKeyFacts(): array
    {
        $dossier = $this->dossier();
        $search = $dossier->getSearch();

        $tenants = 0;
        $income = 0;
        $language = null;
        foreach ($dossier->getPersons() as $person) {
            if (DossierPersonRole::TENANT === $person->getRole()) {
                ++$tenants;
                $income += $person->getMonthlyIncome() ?? 0;
            }
            // The primary contact drives the language we write in; fall back
            // to the first person who declared one.
            if ($person->isPrimaryContact() && null !== $person->getLanguage()) {
                $language = $person->getLanguage()->value;
            } elseif (null === $language && null !== $person->getLanguage()) {
                $language = $person->getLanguage()->value;
            }
        }

        // Landlord's "3x rule": the rent must stay under a third of the
        // tenants' combined net income, otherwise no application goes through.
        $budget = $search?->getBudget();
        $maxAffordable = $income > 0 ? intdiv($income, 3) : null;

        return [
            'budget' => $budget,
            'maxAffordable' => $maxAffordable,
            'overBudget' => null !== $budget && null !== $maxAffordable && $budget > $maxAffordable,
            'tenants' => $tenants,
            'household' => $search?->getHouseholdType(),
            'language' => $language,
            'guarantor' => $search?->getGuarantorType(),
            'guarantorStatus' => $search?->getGuarantorStatus(),
        ];
    }

    private function dossier(): Dossier
    {
        return $this->dossiers->find($this->dossierId)
            ?? throw new NotFoundHttpException('Dossier not found.');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_DOSSIERS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
