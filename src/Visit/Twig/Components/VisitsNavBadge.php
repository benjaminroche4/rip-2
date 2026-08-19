<?php

declare(strict_types=1);

namespace App\Visit\Twig\Components;

use App\Visit\Repository\VisitRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Counter badge next to the "Visites" sidebar link showing how many visits
 * are scheduled today (hidden at zero: no tour, no badge).
 */
#[AsTwigComponent(name: 'Visit:VisitsNavBadge', template: 'components/Visit/VisitsNavBadge.html.twig')]
final class VisitsNavBadge
{
    public function __construct(
        private readonly VisitRepository $repository,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    public function mount(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_VISITS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }

    public function getCount(): int
    {
        // Jour courant en heure murale Paris (horloge injectée, testable) :
        // même convention que le reste du contexte Visite.
        return $this->repository->countOnDay(
            $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Paris')),
        );
    }
}
