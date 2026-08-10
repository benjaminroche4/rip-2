<?php

declare(strict_types=1);

namespace App\Visit\Twig\Components;

use App\Dossier\Repository\DossierRepository;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
use App\Visit\Entity\Visit;
use App\Visit\Form\VisitType;
use App\Visit\Service\AddressGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;

/**
 * "New visit" split screen: the form on the left, a live summary card on the
 * right that fills in as the operator types. The address is geocoded on the
 * fly (debounced locate action through the same AddressGeocoder as creation)
 * so the summary card shows the property pinned on a mini map before saving.
 */
#[AsLiveComponent(name: 'Visit:VisitForm', template: 'components/Visit/VisitForm.html.twig')]
final class VisitForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    private const TIMEZONE = 'Europe/Paris';

    #[LiveProp(fieldName: 'formData')]
    public ?Visit $visit = null;

    #[LiveProp]
    public string $adminPrefix = '';

    /** Coordinates of the last successfully located address. */
    #[LiveProp]
    public ?float $previewLat = null;

    #[LiveProp]
    public ?float $previewLng = null;

    /** Address the preview coordinates belong to — avoids re-geocoding. */
    #[LiveProp]
    public string $locatedAddress = '';

    public function __construct(
        private readonly Security $security,
        private readonly DossierRepository $dossiers,
        private readonly RealEstateAgentRepository $agents,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->visit ??= new Visit();
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(VisitType::class, $this->visit ??= new Visit());
    }

    /**
     * Fired when a suggestion of the places-autocomplete dropdown is picked
     * (same Places flow as the public listing form): the browser already has
     * the coordinates, no server-side geocoding involved.
     */
    #[LiveAction]
    public function setLocation(#[LiveArg] ?float $lat = null, #[LiveArg] ?float $lng = null): void
    {
        $this->ensureAdmin();

        if (null === $lat || null === $lng) {
            return;
        }

        $this->previewLat = $lat;
        $this->previewLng = $lng;
        // The autocomplete wrote the formatted address into the input right
        // before triggering this action, so the current form value is the
        // address these coordinates belong to.
        $this->locatedAddress = trim((string) ($this->formValues['address'] ?? ''));
    }

    public function getSummaryDossier(): ?string
    {
        $id = (int) ($this->formValues['dossier'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $dossier = $this->dossiers->find($id);

        return null !== $dossier ? $dossier->getName().' ('.$dossier->getReference().')' : null;
    }

    public function getSummaryAgent(): ?string
    {
        $id = (int) ($this->formValues['agent'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $agent = $this->agents->find($id);
        if (null === $agent) {
            return null;
        }

        $name = trim($agent->getFirstName().' '.$agent->getLastName());
        $agency = $agent->getAgency()?->getName();

        return null !== $agency ? $name.' ('.$agency.')' : $name;
    }

    public function getSummaryScheduledAt(): ?\DateTimeImmutable
    {
        $raw = trim((string) ($this->formValues['scheduledAt'] ?? ''));
        if ('' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw, new \DateTimeZone(self::TIMEZONE));
        } catch (\Exception) {
            return null;
        }
    }

    public function getSummaryAddress(): ?string
    {
        $address = trim((string) ($this->formValues['address'] ?? ''));

        return '' !== $address ? $address : null;
    }

    /** Mini map of the summary card, pinned on the located address. */
    public function getPreviewMap(): ?Map
    {
        if (null === $this->previewLat || null === $this->previewLng) {
            return null;
        }

        // Editing the address after a selection makes the pin stale: hide it
        // until a new suggestion is picked.
        if (trim((string) ($this->formValues['address'] ?? '')) !== $this->locatedAddress) {
            return null;
        }

        $point = new Point($this->previewLat, $this->previewLng);

        return (new Map('default'))
            ->center($point)
            ->zoom(15)
            ->options(new GoogleOptions(
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
            ))
            ->addMarker(new Marker(position: $point));
    }

    #[LiveAction]
    public function create(EntityManagerInterface $em, AddressGeocoder $geocoder): RedirectResponse
    {
        $this->ensureAdmin();

        // Throws UnprocessableEntityHttpException on invalid input — the
        // component re-renders with the field errors.
        $this->submitForm();

        /** @var Visit $visit */
        $visit = $this->getForm()->getData();

        // Reuse the coordinates already located for the preview when they
        // match the submitted address; geocode once otherwise.
        $address = trim((string) $visit->getAddress());
        if (null !== $this->previewLat && null !== $this->previewLng && $address === $this->locatedAddress) {
            $visit->setLatitude($this->previewLat);
            $visit->setLongitude($this->previewLng);
        } else {
            $point = $geocoder->geocode($address);
            if (null !== $point) {
                $visit->setLatitude($point->latitude);
                $visit->setLongitude($point->longitude);
            }
        }

        $visit->setCreatedAt(new \DateTimeImmutable());
        $em->persist($visit);
        $em->flush();

        return $this->redirectToRoute('admin_visits', [
            'adminPrefix' => $this->adminPrefix,
        ]);
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_VISITS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
