<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Dossier\Repository\DossierRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Terminal-state banner of the dossier detail page, twin of
 * Admin:ContactTerminalBanner on a lead. Live so it appears the moment the
 * dossier is closed from the follow-up card, and vanishes on reopening.
 */
#[AsLiveComponent(name: 'Dossier:ClosedBanner', template: 'components/Dossier/ClosedBanner.html.twig')]
final class ClosedBanner
{
    use DossiersSectionGuard;
    use DefaultActionTrait;

    #[LiveProp]
    public int $dossierId = 0;

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly \App\Dossier\Repository\DossierEventRepository $events,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[LiveListener('dossier-closure:changed')]
    public function refresh(): void
    {
        // Re-render is the whole point.
        $this->ensureAdmin();
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->dossiers->find($this->dossierId)?->getClosedAt();
    }

    /**
     * Qui a clôturé, lu sur la piste d'audit (l'événement porte déjà le nom
     * et l'avatar de son auteur au moment du geste).
     *
     * @return array{name: string, avatar: ?string}|null
     */
    public function getClosedBy(): ?array
    {
        $event = $this->events->findLatestOfKind($this->dossierId, 'dossier_closed');
        $name = $event?->getAuthorName();

        return null !== $name && '' !== $name
            ? ['name' => $name, 'avatar' => $event->getAuthorAvatar()]
            : null;
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_DOSSIERS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
