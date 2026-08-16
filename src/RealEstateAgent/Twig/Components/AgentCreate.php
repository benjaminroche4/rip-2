<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Form\RealEstateAgentType;
use App\RealEstateAgent\Repository\AgencyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "New agent" form on its dedicated admin page: identity plus optional
 * agency and contact details. Redirects back to the list once created so
 * the fresh agent shows up in alphabetical order.
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgentCreate', template: 'components/RealEstateAgent/AgentCreate.html.twig')]
final class AgentCreate extends AbstractController
{
    use AgentsSectionGuard;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?RealEstateAgent $agent = null;

    #[LiveProp]
    public string $adminPrefix = '';

    /** Quartiers favoris (codes ParisDistricts en CSV), pilotés par la carte.
        Hors formulaire : même câblage LiveProp que sur la fiche agence. */
    #[LiveProp(writable: true)]
    public string $areas = '';

    /** Coordonnées posées par la sélection Places (l'adresse tapée à la main
        retombe sur un géocodage serveur au moment de la création). */
    #[LiveProp]
    public ?float $addressLat = null;

    #[LiveProp]
    public ?float $addressLng = null;

    /** Agence sélectionnée dans le dropdown (posée par chooseAgency, jamais
        writable côté client). */
    #[LiveProp]
    public ?int $agencyId = null;

    /** "Sélectionnez une agence." sous le dropdown quand kind = agency sans
        sélection (ou avec une agence disparue/désactivée). */
    #[LiveProp]
    public bool $agencyError = false;

    /** @var list<\App\RealEstateAgent\Domain\AgencyPickerOption>|null */
    private ?array $agencyOptionsCache = null;

    public function __construct(
        private readonly Security $security,
        private readonly AgencyRepository $agencies,
        private readonly \App\RealEstateAgent\Repository\RealEstateAgentRepository $agents,
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly \App\Auth\Service\AvatarDownloader $avatars,
        private readonly \App\Visit\Service\AddressGeocoder $geocoder,
    ) {
    }

    /**
     * Garde-fou anti-doublons, non bloquant : dès qu'un email ou un numéro
     * saisi correspond à une fiche existante, la bande d'avertissement
     * pointe vers elle (la création reste possible).
     *
     * @return array{name: string, reference: string, field: 'email'|'phone'}|null
     */
    public function getDuplicate(): ?array
    {
        $email = trim((string) ($this->formValues['email'] ?? ''));
        $phone = trim((string) ($this->formValues['phone'] ?? ''));
        $match = $this->agents->findPotentialDuplicate($email, $phone);
        if (!$match instanceof RealEstateAgent) {
            return null;
        }

        return [
            'name' => trim($match->getFirstName().' '.$match->getLastName()),
            'reference' => (string) $match->getReference(),
            'field' => '' !== $email && 0 === strcasecmp((string) $match->getEmail(), $email) ? 'email' : 'phone',
        ];
    }

    /**
     * Active agencies for the picker dropdown, alphabetical. Memoized: the
     * template reads it several times per render (options + selection).
     *
     * @return list<\App\RealEstateAgent\Domain\AgencyPickerOption>
     */
    public function getAgencyOptions(): array
    {
        return $this->agencyOptionsCache ??= $this->agencies->findPickerOptions();
    }

    public function getSelectedAgency(): ?\App\RealEstateAgent\Domain\AgencyPickerOption
    {
        foreach ($this->getAgencyOptions() as $option) {
            if ($option->id === $this->agencyId) {
                return $option;
            }
        }

        return null;
    }

    /** Sélection d'une agence existante dans le dropdown (jamais de création ici). */
    #[LiveAction]
    public function chooseAgency(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        foreach ($this->getAgencyOptions() as $option) {
            if ($option->id === $id) {
                $this->agencyId = $id;
                $this->agencyError = false;

                return;
            }
        }

        throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unknown agency.');
    }

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->agent ??= new RealEstateAgent();
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(RealEstateAgentType::class, $this->agent ??= new RealEstateAgent());
    }

    /**
     * Single-select chips with toggle-off: clicking the active position
     * chip clears it (a radio cannot untoggle itself natively).
     */
    #[LiveAction]
    public function togglePosition(#[LiveArg] string $position): void
    {
        $this->ensureAdmin();

        $current = $this->formValues['position'] ?? '';
        $this->formValues['position'] = $current === $position ? '' : $position;
    }

    /** Re-rendu des chips après une sélection sur la carte (rien n'est persisté). */
    #[LiveAction]
    public function syncAreas(): void
    {
        $this->ensureAdmin();
    }

    /** Sélection Places sur le champ adresse : coordonnées immédiates. */
    #[LiveAction]
    public function chooseAddressLocation(#[LiveArg] ?float $lat = null, #[LiveArg] ?float $lng = null): void
    {
        $this->ensureAdmin();
        // Bornes WGS84 : des coordonnées client hors plage sont ignorées.
        if (null !== $lat && null !== $lng && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
            $this->addressLat = $lat;
            $this->addressLng = $lng;
        }
    }

    /** Carte des quartiers, même cadrage que la fiche agence. */
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

    #[LiveAction]
    public function create(EntityManagerInterface $em, \Symfony\Contracts\Translation\TranslatorInterface $translator): ?RedirectResponse
    {
        $this->ensureAdmin();
        $this->agencyError = false;

        // Throws UnprocessableEntityHttpException on invalid input — the
        // component re-renders with the field errors, form stays in place.
        $this->submitForm();

        /** @var RealEstateAgent $agent */
        $agent = $this->getForm()->getData();

        // Téléphone normalisé E.164 côté serveur (même canon que les leads);
        // un numéro invalide est refusé avec une erreur de champ.
        if (null !== $agent->getPhone()) {
            $e164 = \App\Shared\Phone\PhoneNumberNormalizer::toE164($agent->getPhone());
            if (null === $e164) {
                $this->getForm()->get('phone')->addError(
                    new \Symfony\Component\Form\FormError($translator->trans('admin.contacts.edit.invalidPhone')),
                );
                throw new UnprocessableEntityHttpException('Invalid phone number.');
            }
            $agent->setPhone($e164);
        }

        // Picking an existing agency is only meaningful (and then required)
        // when the agent works for one; an independent agent ignores any
        // leftover selection made before switching the kind chip.
        $kind = (string) $this->getForm()->get('kind')->getData();
        if ('agency' === $kind) {
            $agency = null !== $this->agencyId ? $this->agencies->find($this->agencyId) : null;
            if (!$agency instanceof \App\RealEstateAgent\Entity\Agency || !$agency->isActive()) {
                $this->agencyError = true;
                throw new UnprocessableEntityHttpException('An existing active agency is required for an agency agent.');
            }
            $agent->setAgency($agency);
        } else {
            $agent->setAgency(null);
        }

        // The position only makes sense inside an agency: a leftover value
        // picked before switching the kind chip to independent is dropped.
        if ('agency' !== $kind) {
            $agent->setPosition(null);
        }

        // Adresse et quartiers favoris : réservés aux indépendants (un agent
        // en agence porte l'adresse de son agence). Un reliquat saisi avant
        // de basculer le chip vers "En agence" est abandonné, comme le poste.
        if ('agency' === $kind) {
            $agent->setAddress(null)->setAreas(null)->setLatitude(null)->setLongitude(null);
        } else {
            // Quartiers favoris : CSV whitelisté contre les codes connus.
            $areaCodes = array_values(array_filter(
                array_map(trim(...), explode(',', $this->areas)),
                static fn (string $code): bool => isset(\App\Contact\Domain\ParisDistricts::LABELS[$code]),
            ));
            $agent->setAreas([] !== $areaCodes ? implode(',', $areaCodes) : null);

            // Coordonnées : la sélection Places les a posées; une adresse
            // tapée à la main retombe sur un géocodage serveur.
            $lat = $this->addressLat;
            $lng = $this->addressLng;
            if (null === $agent->getAddress()) {
                $lat = $lng = null;
            } elseif (null === $lat || null === $lng) {
                $point = $this->geocoder->geocode((string) $agent->getAddress());
                $lat = $point?->latitude;
                $lng = $point?->longitude;
            }
            $agent->setLatitude($lat)->setLongitude($lng);
        }

        $agent->setCreatedAt(new \DateTimeImmutable());
        // Instantané du créateur (nom + photo de profil) : traçabilité de
        // fiche, robuste même si le compte staff disparaît.
        $creator = $this->security->getUser();
        if ($creator instanceof \App\Auth\Entity\User) {
            $fullName = trim(($creator->getFirstName() ?? '').' '.($creator->getLastName() ?? ''));
            $agent->setCreatedByName('' !== $fullName ? $fullName : (string) $creator->getEmail());
            $agent->setCreatedByAvatar($creator->getAvatarFilename());
        }
        // Référence aléatoire : re-tirée si elle existe déjà (sinon l'index
        // unique transformerait la collision en 500 à la création).
        $this->agents->ensureUniqueReference($agent);
        $em->persist($agent);
        $em->flush();

        // Photo optionnelle, envoyée avec l'action (input file "photo") :
        // normalisée WebP 256x256 et stockée sous agents/<id>/avatar/. Un
        // fichier illisible n'empêche jamais la création (best-effort).
        $upload = $this->requestStack->getCurrentRequest()?->files->get('photo');
        if ($upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $bytes = (string) @file_get_contents($upload->getPathname());
            $stored = '' !== $bytes ? $this->avatars->storeFromBytes($bytes, (string) $agent->getId(), 'agents', 'avatar') : null;
            if (null !== $stored) {
                $agent->setAvatarFilename($stored);
                $em->flush();
            }
        }

        try {
            $this->addFlash('success', 'admin.toast.agentCreated');
        } catch (\LogicException) {
            // Sessionless context (component tests): no toast to queue.
        }

        return $this->redirectToRoute('admin_agents', [
            'adminPrefix' => $this->adminPrefix,
        ]);
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_AGENTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
