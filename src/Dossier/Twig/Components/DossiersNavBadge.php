<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Dossier\Repository\DossierRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Counter badge next to the "Dossiers" sidebar link showing how many
 * dossiers are currently open (not closed).
 */
#[AsTwigComponent(name: 'Dossier:DossiersNavBadge', template: 'components/Dossier/DossiersNavBadge.html.twig')]
final class DossiersNavBadge
{
    public function __construct(
        private readonly DossierRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_DOSSIERS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }

    public function getCount(): int
    {
        return $this->repository->countOpen();
    }
}
