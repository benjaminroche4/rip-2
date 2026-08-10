<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

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

    public function getMoveInAt(): ?\DateTimeImmutable
    {
        return $this->dossier()->getSearch()?->getMoveInAt();
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
