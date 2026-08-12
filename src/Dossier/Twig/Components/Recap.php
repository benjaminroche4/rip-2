<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Dossier\Domain\DossierRecap;
use App\Dossier\Entity\Dossier;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\DossierRecapGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * AI quick recap card on the dossier detail page. Displays the persisted
 * recap; the LLM is only called through the explicit (re)generate action,
 * synchronously (a few seconds, acceptable for an on-demand admin action
 * and no worker needed on o2switch).
 */
#[AsLiveComponent(name: 'Dossier:Recap', template: 'components/Dossier/Recap.html.twig')]
final class Recap
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $dossierId = 0;

    /** Soft error flag: the model was unreachable or answered garbage. */
    #[LiveProp]
    public bool $failed = false;

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly DossierRecapGenerator $generator,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[LiveAction]
    public function generate(): void
    {
        $this->ensureAdmin();

        $this->failed = null === $this->generator->generate($this->dossier());
    }

    public function getRecap(): ?DossierRecap
    {
        $dossier = $this->dossier();
        if (null === $dossier->getRecapJson()) {
            return null;
        }

        return DossierRecap::fromJson($dossier->getRecapJson(), $dossier->getRecapGeneratedAt());
    }

    private function dossier(): Dossier
    {
        return $this->dossiers->find($this->dossierId)
            ?? throw new NotFoundHttpException('Dossier not found.');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_DOSSIERS')) {
            throw new AccessDeniedException('Dossiers section access required.');
        }
    }
}
