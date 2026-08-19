<?php

declare(strict_types=1);

namespace App\Visit\Service;

use App\Visit\Entity\Visit;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ligne descriptive du bien visité ("34 m² · RDC · Studio" + loyer),
 * même vocabulaire que le récap du formulaire de création mais lue sur
 * l'entité. Partagée entre la card détail (composant live) et la page
 * (rendus statiques).
 */
final readonly class VisitPropertyRecap
{
    public function __construct(
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return array{facts: list<string>, rent: string|null}
     */
    public function describe(Visit $visit): array
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'fr';
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $number = static fn (?float $value): ?string => null !== $value ? (string) $formatter->format($value) : null;

        $facts = [];
        if (null !== ($surface = $number($visit->getSurface()))) {
            $facts[] = $this->translator->trans('admin.visits.create.propertyDetails.recap.surface', ['%surface%' => $surface]);
        }
        if (null !== ($floor = $visit->getFloor())) {
            $facts[] = 0 === $floor
                ? $this->translator->trans('admin.visits.create.propertyDetails.recap.groundFloor')
                : $this->translator->trans('admin.visits.create.propertyDetails.recap.floor', ['%floor%' => $floor]);
        }
        if (null !== ($kind = $visit->getPropertyKind()) && '' !== $kind) {
            $facts[] = $this->translator->trans('listProperty.form.propertyType.choice.'.$kind);
        }
        $furnishingKey = \App\Contact\Domain\Furnishing::tryFrom((string) $visit->getFurnishing())?->labelKey();
        if (null !== $furnishingKey) {
            $facts[] = $this->translator->trans($furnishingKey);
        }
        if (null !== ($leaseType = $visit->getLeaseType())) {
            $facts[] = $this->translator->trans($leaseType->labelKey());
        }

        $rentCc = true === $visit->getRentChargesIncluded();
        $rent = $number($visit->getRentExcludingCharges());
        $charges = $rentCc ? null : $number($visit->getCharges());
        if (null !== $rent) {
            $line = $this->translator->trans($rentCc ? 'admin.visits.create.propertyDetails.recap.rentCc' : 'admin.visits.create.propertyDetails.recap.rent', ['%amount%' => $rent]);
            if (null !== $charges) {
                $line .= ' '.$this->translator->trans('admin.visits.create.propertyDetails.recap.charges', ['%amount%' => $charges]);
            }
            $rentLine = $line;
        } elseif (null !== $charges) {
            $rentLine = $this->translator->trans('admin.visits.create.propertyDetails.recap.chargesAlone', ['%amount%' => $charges]);
        } else {
            $rentLine = null;
        }

        return ['facts' => $facts, 'rent' => $rentLine];
    }

    /**
     * Toutes les caractéristiques du bien en paires label/valeur, champs
     * vides compris (valeur null = "-" à l'affichage) : la fiche montre
     * l'intégralité des champs, renseignés ou non.
     *
     * @return list<array{label: string, value: string|null}>
     */
    public function rows(Visit $visit): array
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'fr';
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $number = static fn (?float $value): ?string => null !== $value ? (string) $formatter->format($value) : null;

        $surface = $number($visit->getSurface());
        $floor = $visit->getFloor();
        $kind = (string) $visit->getPropertyKind();
        $furnishingKey = \App\Contact\Domain\Furnishing::tryFrom((string) $visit->getFurnishing())?->labelKey();
        $rentCc = true === $visit->getRentChargesIncluded();
        $rent = $number($visit->getRentExcludingCharges());
        $charges = $rentCc ? null : $number($visit->getCharges());

        return [
            [
                'label' => 'admin.visits.create.propertyDetails.surface.label',
                'value' => null !== $surface ? $this->translator->trans('admin.visits.create.propertyDetails.recap.surface', ['%surface%' => $surface]) : null,
            ],
            [
                'label' => 'admin.visits.create.propertyDetails.floor.label',
                'value' => null !== $floor
                    ? (0 === $floor
                        ? $this->translator->trans('admin.visits.create.propertyDetails.recap.groundFloor')
                        : $this->translator->trans('admin.visits.create.propertyDetails.recap.floor', ['%floor%' => $floor]))
                    : null,
            ],
            [
                'label' => 'admin.visits.create.propertyDetails.propertyKind.label',
                'value' => '' !== $kind ? $this->translator->trans('listProperty.form.propertyType.choice.'.$kind) : null,
            ],
            [
                'label' => 'admin.visits.create.propertyDetails.furnishing.label',
                'value' => null !== $furnishingKey ? $this->translator->trans($furnishingKey) : null,
            ],
            [
                'label' => 'admin.visits.create.propertyDetails.leaseType.label',
                'value' => null !== $visit->getLeaseType() ? $this->translator->trans($visit->getLeaseType()->labelKey()) : null,
            ],
            [
                'label' => 'admin.visits.create.propertyDetails.rentMode.label',
                'value' => null !== $rent
                    ? $this->translator->trans($rentCc ? 'admin.visits.create.propertyDetails.recap.rentCc' : 'admin.visits.create.propertyDetails.recap.rent', ['%amount%' => $rent])
                    : null,
            ],
            [
                'label' => 'admin.visits.create.propertyDetails.charges.label',
                'value' => null !== $charges ? $charges.' €' : null,
            ],
        ];
    }
}
