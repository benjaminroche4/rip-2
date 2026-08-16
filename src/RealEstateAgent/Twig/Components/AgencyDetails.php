<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use App\Auth\Service\AvatarDownloader;
use App\RealEstateAgent\Domain\AgencyDetail;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Repository\AgencyRepository;
use App\RealEstateAgent\Repository\BrandRepository;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Agency identity card on the detail page, editable in place (direct
 * pencil) with a guarded deletion; its agents fall back to independent
 * (SET NULL foreign key), nothing else is removed.
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgencyDetails', template: 'components/RealEstateAgent/AgencyDetails.html.twig')]
final class AgencyDetails extends AbstractController
{
    use AgentsSectionGuard;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $agencyId = 0;

    #[LiveProp]
    public string $adminPrefix = '';

    #[LiveProp]
    public bool $editing = false;

    #[LiveProp]
    public bool $confirmingDelete = false;

    #[LiveProp]
    public bool $confirmingDeactivate = false;

    /** Validation errors keyed by field, shown under the inputs. */
    #[LiveProp]
    public array $errors = [];

    #[LiveProp(writable: true)]
    public string $name = '';

    /** Free text: a known name reuses the brand, a new one creates it, empty = no brand. */
    #[LiveProp(writable: true)]
    public string $brandName = '';

    #[LiveProp(writable: true)]
    public string $address = '';

    #[LiveProp(writable: true)]
    public string $phone = '';

    #[LiveProp(writable: true)]
    public string $email = '';

    #[LiveProp(writable: true)]
    public string $website = '';

    #[LiveProp(writable: true)]
    public string $note = '';

    /** @var list<string> chip-toggled, whitelisted against AgentSpecialty */
    #[LiveProp]
    public array $specialties = [];

    /** Quartiers favoris (codes ParisDistricts en CSV), pilotés par la carte. */
    #[LiveProp(writable: true)]
    public string $areas = '';

    /** Coordonnées posées par la sélection Places (l'adresse tapée à la main
        retombe sur un géocodage serveur au moment de l'enregistrement). */
    #[LiveProp]
    public ?float $addressLat = null;

    #[LiveProp]
    public ?float $addressLng = null;

    public function __construct(
        private readonly Security $security,
        private readonly AgencyRepository $agencies,
        private readonly BrandRepository $brands,
        private readonly RealEstateAgentRepository $agents,
        private readonly AvatarDownloader $avatars,
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly \App\Visit\Service\AddressGeocoder $geocoder,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public function mount(int $agencyId): void
    {
        $this->ensureAdmin();
        $this->agencyId = $agencyId;
        $this->prefill();
    }

    public function getAgency(): ?AgencyDetail
    {
        return $this->agencies->findDetail($this->agencyId);
    }

    /**
     * Existing brand names for the edit datalist.
     *
     * @return list<string>
     */
    public function getBrandNames(): array
    {
        return $this->brands->findAllNames();
    }

    public function getAgentCount(): int
    {
        return \count($this->agents->findSummariesByAgency($this->agencyId));
    }

    #[LiveAction]
    public function startEditing(): void
    {
        $this->ensureAdmin();
        $this->prefill();
        $this->editing = true;
    }

    #[LiveAction]
    public function cancelEditing(): void
    {
        $this->ensureAdmin();
        $this->prefill();
        $this->editing = false;
        $this->errors = [];
    }

    #[LiveAction]
    public function saveDetails(EntityManagerInterface $em, TranslatorInterface $translator): void
    {
        $this->ensureAdmin();

        $this->errors = [];
        $name = trim($this->name);
        if ('' === $name) {
            $this->errors['name'] = 'admin.contacts.edit.required';
        } else {
            // Uniqueness, ignoring the agency being edited itself.
            $existing = $this->agencies->findByName($name);
            if (null !== $existing && $existing->getId() !== $this->agencyId) {
                $this->errors['name'] = 'admin.agencies.create.name.exists';
            }
        }
        if ('' !== trim($this->email) && false === filter_var(trim($this->email), \FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = 'admin.contacts.edit.invalidEmail';
        }
        // Same normalisation as the entity setter (https:// prefixed when
        // no scheme was typed), then a plain URL check.
        $website = trim($this->website);
        if ('' !== $website && !preg_match('~^https?://~i', $website)) {
            $website = 'https://'.$website;
        }
        if ('' !== $website && (false === filter_var($website, \FILTER_VALIDATE_URL) || !str_contains((string) parse_url($website, \PHP_URL_HOST), '.'))) {
            $this->errors['website'] = 'admin.agencies.create.website.invalid';
        }
        if ([] !== $this->errors) {
            return;
        }

        $agency = $this->agencies->find($this->agencyId)
            ?? throw new NotFoundHttpException('Unknown agency.');

        // Quartiers favoris : CSV whitelisté contre les codes connus.
        $areaCodes = array_values(array_filter(
            array_map(trim(...), explode(',', $this->areas)),
            static fn (string $code): bool => isset(\App\Contact\Domain\ParisDistricts::LABELS[$code]),
        ));
        $areas = [] !== $areaCodes ? implode(',', $areaCodes) : null;

        // Coordonnées : la sélection Places les a posées; une adresse tapée
        // à la main (coordonnées inchangées) retombe sur un géocodage serveur.
        $address = '' !== trim($this->address) ? trim($this->address) : null;
        $lat = $this->addressLat;
        $lng = $this->addressLng;
        if (null === $address) {
            $lat = $lng = null;
        } elseif ($address !== $agency->getAddress() && $lat === $agency->getLatitude() && $lng === $agency->getLongitude()) {
            $point = $this->geocoder->geocode($address);
            $lat = $point?->latitude;
            $lng = $point?->longitude;
        }

        $brandName = trim($this->brandName);
        $agency
            ->setName($name)
            ->setBrand('' !== $brandName ? $this->brands->findOrCreate($brandName) : null)
            ->setAddress($address)
            ->setAreas($areas)
            ->setLatitude($lat)
            ->setLongitude($lng)
            ->setPhone('' !== trim($this->phone) ? trim($this->phone) : null)
            ->setEmail('' !== trim($this->email) ? trim($this->email) : null)
            ->setWebsite($this->website)
            ->setSpecialties(array_map(AgentSpecialty::from(...), $this->specialties))
            ->setNote('' !== trim($this->note) ? trim($this->note) : null);

        // Nouveau logo éventuel (FormData du bouton "files|saveDetails"),
        // normalisé WebP 256x256; l'ancien fichier est purgé du bucket.
        $upload = $this->requestStack->getCurrentRequest()?->files->get('logo');
        if ($upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $bytes = (string) @file_get_contents($upload->getPathname());
            $stored = '' !== $bytes ? $this->avatars->storeFromBytes($bytes, (string) $agency->getId(), 'agencies', 'logo') : null;
            if (null !== $stored) {
                if (null !== $agency->getLogoFilename() && $agency->getLogoFilename() !== $stored) {
                    $this->avatars->delete($agency->getLogoFilename());
                }
                $agency->setLogoFilename($stored);
            }
        }
        $em->flush();

        $this->prefill();
        $this->editing = false;
        // The page chrome (title, breadcrumb) lives outside the component:
        // a Turbo morph refresh picks up the new name.
        $this->dispatchBrowserEvent('agency-identity:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $translator->trans('admin.toast.saved')]);
    }

    /** Multi-select chips with toggle-off, like the creation form. */
    #[LiveAction]
    public function toggleSpecialty(#[LiveArg] string $specialty): void
    {
        $this->ensureAdmin();

        if (null === AgentSpecialty::tryFrom($specialty)) {
            throw new BadRequestHttpException(\sprintf('Unknown specialty "%s".', $specialty));
        }
        $this->specialties = \in_array($specialty, $this->specialties, true)
            ? array_values(array_diff($this->specialties, [$specialty]))
            : [...$this->specialties, $specialty];
    }

    #[LiveAction]
    public function askDeactivate(): void
    {
        $this->ensureAdmin();
        $this->confirmingDeactivate = true;
    }

    #[LiveAction]
    public function cancelDeactivate(): void
    {
        $this->ensureAdmin();
        $this->confirmingDeactivate = false;
    }

    /** Confirmed in a modal: the agency leaves the pickers, nothing is deleted. */
    #[LiveAction]
    public function deactivateAgency(EntityManagerInterface $em, TranslatorInterface $translator): void
    {
        $this->ensureAdmin();

        $agency = $this->agencies->find($this->agencyId)
            ?? throw new NotFoundHttpException('Unknown agency.');
        $agency->setDeactivatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->securityLogger->notice('Agency deactivated from the back office.', [
            'agencyId' => $agency->getId(),
            'agencyName' => $agency->getName(),
            'by' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        $this->confirmingDeactivate = false;
        $this->dispatchBrowserEvent('toast:show', ['message' => $translator->trans('admin.toast.saved')]);
    }

    /** Direct action, no modal: reactivating is harmless. */
    #[LiveAction]
    public function reactivateAgency(EntityManagerInterface $em, TranslatorInterface $translator): void
    {
        $this->ensureAdmin();

        $agency = $this->agencies->find($this->agencyId)
            ?? throw new NotFoundHttpException('Unknown agency.');
        $agency->setDeactivatedAt(null);
        $em->flush();

        $this->securityLogger->notice('Agency reactivated from the back office.', [
            'agencyId' => $agency->getId(),
            'agencyName' => $agency->getName(),
            'by' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        $this->dispatchBrowserEvent('toast:show', ['message' => $translator->trans('admin.toast.saved')]);
    }

    #[LiveAction]
    public function askDelete(): void
    {
        $this->ensureAdmin();
        // Suppression réservée aux administrateurs (le menu la masque déjà
        // pour le staff de section, ceci couvre l'appel direct au endpoint).
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Deletion is admin-only.');
        }
        $this->confirmingDelete = true;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->ensureAdmin();
        $this->confirmingDelete = false;
    }

    #[LiveAction]
    public function deleteAgency(EntityManagerInterface $em): RedirectResponse
    {
        $this->ensureAdmin();
        // Suppression réservée aux administrateurs (le menu la masque déjà
        // pour le staff de section, ceci couvre l'appel direct au endpoint).
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Deletion is admin-only.');
        }

        $agency = $this->agencies->find($this->agencyId)
            ?? throw new NotFoundHttpException('Unknown agency.');

        // Stored logo removed with the profile (nothing must outlive it).
        if (null !== $agency->getLogoFilename()) {
            $this->avatars->delete($agency->getLogoFilename());
        }

        $this->securityLogger->warning('Agency profile deleted from the back office.', [
            'agencyId' => $agency->getId(),
            'agencyName' => $agency->getName(),
            'agentCount' => \count($this->agents->findSummariesByAgency((int) $agency->getId())),
            'by' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        // Its agents keep existing as independent (SET NULL foreign key).
        $em->remove($agency);
        $em->flush();

        try {
            $this->addFlash('success', 'admin.toast.agencyDeleted');
        } catch (\LogicException) {
            // Sessionless context (component tests): no toast to queue.
        }

        return $this->redirectToRoute('admin_agents', [
            'adminPrefix' => $this->adminPrefix,
            'view' => 'agencies',
        ]);
    }

    private function prefill(): void
    {
        $agency = $this->getAgency();
        $this->name = $agency->name ?? '';
        $this->brandName = $agency->brand ?? '';
        $this->address = $agency->address ?? '';
        $this->phone = $agency->phone ?? '';
        $this->email = $agency->email ?? '';
        $this->website = $agency->website ?? '';
        $this->note = $agency->note ?? '';
        $this->specialties = array_map(static fn (AgentSpecialty $s): string => $s->value, $agency->specialties ?? []);
        $this->areas = $agency->areas ?? '';
        $this->addressLat = $agency->latitude;
        $this->addressLng = $agency->longitude;
    }

    /** Sélection Places sur le champ adresse : coordonnées immédiates. */
    #[LiveAction]
    public function chooseAddressLocation(#[LiveArg] ?float $lat = null, #[LiveArg] ?float $lng = null): void
    {
        $this->ensureAdmin();
        if (null !== $lat && null !== $lng) {
            $this->addressLat = $lat;
            $this->addressLng = $lng;
        }
    }

    /** Carte des quartiers, même cadrage que la card Recherche du dossier. */
    public function getMap(): \Symfony\UX\Map\Map
    {
        return (new \Symfony\UX\Map\Map('default'))
            ->center(new \Symfony\UX\Map\Point(48.8566, 2.3522))
            ->zoom(11.2)
            ->options(new \Symfony\UX\Map\Bridge\Google\GoogleOptions(
                gestureHandling: \Symfony\UX\Map\Bridge\Google\Option\GestureHandling::COOPERATIVE,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
                zoomControl: false,
            ));
    }

    /** Mini-carte de l'adresse en lecture (comme "Proposer un bien"). */
    public function getAddressMap(): ?\Symfony\UX\Map\Map
    {
        $agency = $this->getAgency();
        if (null === $agency?->latitude || null === $agency->longitude) {
            return null;
        }
        $point = new \Symfony\UX\Map\Point($agency->latitude, $agency->longitude);

        return (new \Symfony\UX\Map\Map('default'))
            ->center($point)
            ->zoom(15)
            ->options(new \Symfony\UX\Map\Bridge\Google\GoogleOptions(
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
            ))
            ->addMarker(new \Symfony\UX\Map\Marker(position: $point));
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public function getAreaChips(): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'label' => \App\Contact\Domain\ParisDistricts::LABELS[$code] ?? $code],
            array_values(array_filter(array_map(trim(...), explode(',', $this->areas)))),
        );
    }

    public function getAllArrondissementsSelected(): bool
    {
        return \App\Contact\Domain\ParisDistricts::allArrondissementsSelected($this->areas);
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_AGENTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
