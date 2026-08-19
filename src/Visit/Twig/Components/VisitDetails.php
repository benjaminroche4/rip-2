<?php

declare(strict_types=1);

namespace App\Visit\Twig\Components;

use App\Visit\Repository\VisitRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Read-only detail card of the visit page: the property (map, facts,
 * listing link, note), who is involved, the status actions and the
 * follow-up card. Every mutation goes through the dedicated "Modifier"
 * page (menu "..." of the card) or the status/report/photos endpoints:
 * the inline editing (padlock + autosave) is gone.
 */
#[AsLiveComponent(name: 'Visit:VisitDetails', template: 'components/Visit/VisitDetails.html.twig')]
final class VisitDetails
{
    use VisitsSectionGuard;
    use DefaultActionTrait;

    #[LiveProp]
    public int $visitId = 0;

    #[LiveProp]
    public string $adminPrefix = '';

    public function __construct(
        private readonly VisitRepository $visits,
        private readonly Security $security,
        private readonly \App\Visit\Service\VisitPropertyRecap $propertyRecap,
    ) {
    }

    public function mount(int $visitId): void
    {
        $this->ensureAdmin();
        $this->visitId = $visitId;
    }

    public function getVisit(): ?\App\Visit\Domain\VisitSummary
    {
        return $this->visits->findOneSummary($this->visitId);
    }

    /**
     * Mini carte épinglée sur le bien, en tête de la card "Le bien".
     * Rendue dans une zone data-live-ignore (un morph live la détruirait).
     */
    public function getMap(): ?\Symfony\UX\Map\Map
    {
        $visit = $this->getVisit();
        if (null === $visit || null === $visit->latitude || null === $visit->longitude) {
            return null;
        }

        $point = new \Symfony\UX\Map\Point($visit->latitude, $visit->longitude);

        return (new \Symfony\UX\Map\Map('default'))
            ->center($point)
            ->zoom(15)
            ->options(new \Symfony\UX\Map\Bridge\Google\GoogleOptions(
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
            ))
            ->addMarker(new \Symfony\UX\Map\Marker(
                position: $point,
                // Pin maison (goutte bordeaux, pastille blanche, maison) :
                // même signature que les autres cartes du site.
                icon: \Symfony\UX\Map\Icon\Icon::svg(<<<'SVG'
                    <svg xmlns="http://www.w3.org/2000/svg" width="39" height="50" viewBox="0 0 44 56">
                        <path d="M22 0C9.85 0 0 9.85 0 22c0 16.5 22 34 22 34s22-17.5 22-34C44 9.85 34.15 0 22 0Z" fill="#71172e"/>
                        <circle cx="22" cy="22" r="13" fill="white"/>
                        <path d="M22 14.5a2 2 0 0 0-1.28.46l-5.44 4.53A2 2 0 0 0 14.57 21v7.5a1.5 1.5 0 0 0 1.5 1.5h3v-4.5a1.5 1.5 0 0 1 1.5-1.5h2.86a1.5 1.5 0 0 1 1.5 1.5V30h3a1.5 1.5 0 0 0 1.5-1.5V21a2 2 0 0 0-.71-1.51l-5.44-4.53A2 2 0 0 0 22 14.5Z" fill="#71172e" stroke="#71172e" stroke-width="0.5" stroke-linejoin="round"/>
                    </svg>
                    SVG),
                title: (string) $visit->address,
            ));
    }

    /**
     * Toutes les caractéristiques du bien, champs vides compris ("-").
     *
     * @return list<array{label: string, value: string|null}>
     */
    public function getPropertyRows(): array
    {
        $visit = $this->visits->find($this->visitId);

        return null !== $visit ? $this->propertyRecap->rows($visit) : [];
    }

    /**
     * Autres visites déjà prévues sur le même bien (même adresse ou même
     * lien d'annonce), la visite courante exclue. En lecture pure, les
     * valeurs stockées font foi.
     *
     * @return list<\App\Visit\Domain\VisitSummary>
     */
    public function getMatchingVisits(): array
    {
        $visit = $this->visits->find($this->visitId);
        if (null === $visit) {
            return [];
        }

        $address = trim((string) $visit->getAddress());
        if (mb_strlen($address) < 5) {
            $address = '';
        }

        return $this->visits->findMatchingSummaries($address, trim((string) $visit->getListingUrl()), $this->visitId);
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_VISITS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
