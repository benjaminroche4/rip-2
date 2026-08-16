<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use App\Auth\Service\AvatarDownloader;
use App\RealEstateAgent\Domain\AgencyPosition;
use App\RealEstateAgent\Domain\AgentDetail;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Domain\ProfessionalCard;
use App\RealEstateAgent\Repository\AgencyRepository;
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
 * Agent identity card on the detail page, editable in place (direct pencil,
 * same flow as the lead identity card) with a guarded profile deletion.
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgentDetails', template: 'components/RealEstateAgent/AgentDetails.html.twig')]
final class AgentDetails extends AbstractController
{
    use AgentsSectionGuard;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $agentId = 0;

    #[LiveProp]
    public string $adminPrefix = '';

    #[LiveProp]
    public bool $editing = false;

    #[LiveProp]
    public bool $confirmingDelete = false;

    #[LiveProp]
    public bool $confirmingIntroEmail = false;

    #[LiveProp]
    public bool $confirmingDeactivate = false;

    /** Validation errors keyed by field, shown under the inputs. */
    #[LiveProp]
    public array $errors = [];

    #[LiveProp(writable: true)]
    public string $firstName = '';

    #[LiveProp(writable: true)]
    public string $lastName = '';

    #[LiveProp(writable: true)]
    public string $email = '';

    #[LiveProp(writable: true)]
    public string $phone = '';

    /** Agence sélectionnée dans le dropdown (posée par chooseAgency), null = indépendant. */
    #[LiveProp]
    public ?int $agencyId = null;

    /** Adresse de l'agent, réservée aux indépendants (Places autocomplete). */
    #[LiveProp(writable: true)]
    public string $address = '';

    /** Quartiers favoris (codes ParisDistricts en CSV), pilotés par la carte. */
    #[LiveProp(writable: true)]
    public string $areas = '';

    /** Coordonnées posées par la sélection Places (l'adresse tapée à la main
        retombe sur un géocodage serveur au moment de l'enregistrement). */
    #[LiveProp]
    public ?float $addressLat = null;

    #[LiveProp]
    public ?float $addressLng = null;

    #[LiveProp(writable: true)]
    public string $note = '';

    /** @var list<string> chip-toggled, whitelisted against AgentSpecialty */
    #[LiveProp]
    public array $specialties = [];

    /** @var list<string> cartes loi Hoguet (T, G, S), chip-toggled, whitelisted against ProfessionalCard */
    #[LiveProp]
    public array $professionalCards = [];

    /** '' = none; chip-toggled, whitelisted against AgencyPosition. */
    #[LiveProp]
    public string $position = '';

    public function __construct(
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly Security $security,
        private readonly RealEstateAgentRepository $agents,
        private readonly AgencyRepository $agencies,
        private readonly \App\Visit\Repository\VisitRepository $visits,
        private readonly \Symfony\Component\Clock\ClockInterface $clock,
        private readonly AvatarDownloader $avatars,
        private readonly \App\Visit\Service\AddressGeocoder $geocoder,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public function mount(int $agentId): void
    {
        $this->ensureAdmin();
        $this->agentId = $agentId;
        $this->prefill();
    }

    public function getAgent(): ?AgentDetail
    {
        return $this->agents->findDetail($this->agentId);
    }

    /** @var list<\App\RealEstateAgent\Domain\AgencyPickerOption>|null */
    private ?array $agencyOptionsCache = null;

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

    /**
     * Visits booked with this directory agent, split like the dossier visit
     * module: upcoming ones first (soonest on top), then past ones (most
     * recent on top). "Past" starts at the previous midnight.
     *
     * @return array{upcoming: list<\App\Visit\Domain\VisitSummary>, past: list<\App\Visit\Domain\VisitSummary>}
     */
    public function getAgentVisits(): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $upcoming = [];
        $past = [];
        foreach ($this->visits->findByRealEstateAgentSummaries($this->agentId) as $summary) {
            if ($summary->scheduledAt >= $today) {
                $upcoming[] = $summary;
            } else {
                $past[] = $summary;
            }
        }

        return ['upcoming' => $upcoming, 'past' => array_reverse($past)];
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

    /** Multi-select chips with toggle-off, same gesture as the specialties. */
    #[LiveAction]
    public function toggleCard(#[LiveArg] string $card): void
    {
        $this->ensureAdmin();

        if (null === ProfessionalCard::tryFrom($card)) {
            throw new BadRequestHttpException(\sprintf('Unknown professional card "%s".', $card));
        }
        $this->professionalCards = \in_array($card, $this->professionalCards, true)
            ? array_values(array_diff($this->professionalCards, [$card]))
            : [...$this->professionalCards, $card];
    }

    /** Sélection d'une agence existante dans le dropdown (jamais de création ici). */
    #[LiveAction]
    public function chooseAgency(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        foreach ($this->getAgencyOptions() as $option) {
            if ($option->id === $id) {
                $this->agencyId = $id;

                return;
            }
        }

        throw new BadRequestHttpException(\sprintf('Unknown or inactive agency "%d".', $id));
    }

    /** Repli indépendant : plus d'agence (le poste tombe à l'enregistrement). */
    #[LiveAction]
    public function chooseIndependent(): void
    {
        $this->ensureAdmin();
        $this->agencyId = null;
    }

    /** Single-select chips with toggle-off (a radio cannot untoggle itself). */
    #[LiveAction]
    public function togglePosition(#[LiveArg] string $position): void
    {
        $this->ensureAdmin();

        if (null === AgencyPosition::tryFrom($position)) {
            throw new BadRequestHttpException(\sprintf('Unknown position "%s".', $position));
        }
        $this->position = $this->position === $position ? '' : $position;
    }

    #[LiveAction]
    public function saveDetails(EntityManagerInterface $em, TranslatorInterface $translator): void
    {
        $this->ensureAdmin();

        $this->errors = [];
        if ('' === trim($this->firstName)) {
            $this->errors['firstName'] = 'admin.contacts.edit.required';
        }
        if ('' === trim($this->lastName)) {
            $this->errors['lastName'] = 'admin.contacts.edit.required';
        }
        if ('' !== trim($this->email) && false === filter_var(trim($this->email), \FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = 'admin.contacts.edit.invalidEmail';
        }
        // Longueurs des colonnes : le formulaire de création valide via les
        // contraintes de l'entité, l'édition inline doit refuser avant le
        // "Data too long" SQL (500 sans message sinon).
        foreach ([
            'firstName' => [$this->firstName, 50],
            'lastName' => [$this->lastName, 50],
            'email' => [$this->email, 180],
            'address' => [$this->address, 255],
            'note' => [$this->note, 2000],
        ] as $field => [$value, $max]) {
            if (mb_strlen(trim($value)) > $max) {
                $this->errors[$field] = 'admin.contacts.edit.tooLong';
            }
        }
        // Téléphone normalisé E.164 côté serveur (même canon que les leads).
        $phone = null;
        if ('' !== trim($this->phone)) {
            $phone = \App\Shared\Phone\PhoneNumberNormalizer::toE164($this->phone);
            if (null === $phone) {
                $this->errors['phone'] = 'admin.contacts.edit.invalidPhone';
            }
        }
        if ([] !== $this->errors) {
            return;
        }

        $agent = $this->agents->find($this->agentId)
            ?? throw new NotFoundHttpException('Unknown agent.');

        $agency = null;
        if (null !== $this->agencyId) {
            $agency = $this->agencies->find($this->agencyId)
                ?? throw new BadRequestHttpException(\sprintf('Unknown agency "%d".', $this->agencyId));
        }

        // Adresse et quartiers favoris : réservés aux indépendants (un agent
        // en agence porte l'adresse de son agence). Le rattachement à une
        // agence les remet à null, comme le poste côté indépendant.
        $address = null;
        $areas = null;
        $lat = null;
        $lng = null;
        if (null === $agency) {
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
            } elseif ($address !== $agent->getAddress() && $lat === $agent->getLatitude() && $lng === $agent->getLongitude()) {
                $point = $this->geocoder->geocode($address);
                $lat = $point?->latitude;
                $lng = $point?->longitude;
            }
        }

        $agent
            ->setAddress($address)
            ->setAreas($areas)
            ->setLatitude($lat)
            ->setLongitude($lng)
            ->setFirstName(trim($this->firstName))
            ->setLastName(trim($this->lastName))
            ->setEmail('' !== trim($this->email) ? trim($this->email) : null)
            ->setPhone($phone)
            ->setAgency($agency)
            ->setSpecialties(array_map(AgentSpecialty::from(...), $this->specialties))
            ->setProfessionalCards(array_map(ProfessionalCard::from(...), $this->professionalCards))
            // The position only makes sense inside an agency.
            ->setPosition(null !== $agency && '' !== $this->position ? AgencyPosition::from($this->position) : null)
            ->setNote('' !== trim($this->note) ? trim($this->note) : null)
            ->setUpdatedAt(new \DateTimeImmutable())
            ->setUpdatedByName($this->currentStaffName())
            ->setUpdatedByAvatar($this->currentStaffAvatar());
        $em->flush();

        // Nouvelle photo optionnelle, envoyée avec l'action (input file
        // "photo") : normalisée WebP 256x256 et stockée sous agents/<id>/.
        // Un fichier illisible n'empêche jamais l'enregistrement.
        $upload = $this->requestStack->getCurrentRequest()?->files->get('photo');
        if ($upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $bytes = (string) @file_get_contents($upload->getPathname());
            $stored = '' !== $bytes ? $this->avatars->storeFromBytes($bytes, (string) $agent->getId(), 'agents', 'avatar') : null;
            if (null !== $stored) {
                if (null !== $agent->getAvatarFilename()) {
                    $this->avatars->delete($agent->getAvatarFilename());
                }
                $agent->setAvatarFilename($stored);
                $em->flush();
            }
        }

        $this->prefill();
        $this->editing = false;
        // The page chrome (title, breadcrumb) lives outside the component:
        // a Turbo morph refresh picks up the new name.
        $this->dispatchBrowserEvent('agent-identity:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $translator->trans('admin.toast.saved')]);
    }

    /** The switch asks first: turning the profile off is confirmed in a modal. */
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

    /** Confirmed in the modal: the agent leaves the pickers, nothing is deleted. */
    #[LiveAction]
    public function deactivateAgent(EntityManagerInterface $em): void
    {
        $this->ensureAdmin();

        $agent = $this->agents->find($this->agentId)
            ?? throw new NotFoundHttpException('Unknown agent.');
        $agent->setDeactivatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->securityLogger->notice('Real-estate agent deactivated from the back office.', [
            'agentId' => $agent->getId(),
            'agentName' => trim($agent->getFirstName().' '.$agent->getLastName()),
            'by' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        $this->confirmingDeactivate = false;
    }

    /** Direct action, no modal: reactivating is harmless. */
    #[LiveAction]
    public function reactivateAgent(EntityManagerInterface $em): void
    {
        $this->ensureAdmin();

        $agent = $this->agents->find($this->agentId)
            ?? throw new NotFoundHttpException('Unknown agent.');
        $agent->setDeactivatedAt(null);
        $em->flush();

        $this->securityLogger->notice('Real-estate agent reactivated from the back office.', [
            'agentId' => $agent->getId(),
            'agentName' => trim($agent->getFirstName().' '.$agent->getLastName()),
            'by' => $this->security->getUser()?->getUserIdentifier(),
        ]);
    }

    #[LiveAction]
    public function askSendIntro(): void
    {
        $this->ensureAdmin();
        if (null === $this->getAgent()?->email) {
            throw new BadRequestHttpException('The agent has no email address.');
        }
        $this->confirmingIntroEmail = true;
    }

    #[LiveAction]
    public function cancelSendIntro(): void
    {
        $this->ensureAdmin();
        $this->confirmingIntroEmail = false;
    }

    /** Email de présentation : ajout à l'annuaire, infos détenues, notre contact. */
    #[LiveAction]
    public function sendIntroEmail(\App\RealEstateAgent\Service\AgentIntroMailer $mailer, TranslatorInterface $translator, EntityManagerInterface $em): void
    {
        $this->ensureAdmin();

        $agent = $this->getAgent();
        if (null === $agent?->email) {
            throw new BadRequestHttpException('The agent has no email address.');
        }

        $mailer->send($agent);

        // Trace anti-doublon : la modale affiche la date du dernier envoi.
        $entity = $this->agents->find($this->agentId);
        if ($entity instanceof \App\RealEstateAgent\Entity\RealEstateAgent) {
            $entity->setIntroEmailSentAt(new \DateTimeImmutable());
            $em->flush();
        }

        $this->confirmingIntroEmail = false;
        $this->dispatchBrowserEvent('toast:show', ['message' => $translator->trans('admin.agents.introEmail.sent')]);
    }

    /** Favori d'équipe (global) : bascule le cœur du header, la fiche se re-rend. */
    #[LiveAction]
    public function toggleFavorite(EntityManagerInterface $em): void
    {
        $this->ensureAdmin();

        $agent = $this->agents->find($this->agentId)
            ?? throw new NotFoundHttpException('Unknown agent.');
        $agent->setFavoritedAt($agent->isFavorite() ? null : new \DateTimeImmutable());
        $em->flush();
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
    public function deleteAgent(EntityManagerInterface $em): RedirectResponse
    {
        $this->ensureAdmin();
        // Suppression réservée aux administrateurs (le menu la masque déjà
        // pour le staff de section, ceci couvre l'appel direct au endpoint).
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Deletion is admin-only.');
        }

        $agent = $this->agents->find($this->agentId)
            ?? throw new NotFoundHttpException('Unknown agent.');

        // Stored photo removed with the profile (nothing must outlive it).
        if (null !== $agent->getAvatarFilename()) {
            $this->avatars->delete($agent->getAvatarFilename());
        }

        $this->securityLogger->warning('Real-estate agent profile deleted from the back office.', [
            'agentId' => $agent->getId(),
            'agentName' => trim($agent->getFirstName().' '.$agent->getLastName()),
            'by' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        $em->remove($agent);
        $em->flush();

        try {
            $this->addFlash('success', 'admin.toast.agentDeleted');
        } catch (\LogicException) {
            // Sessionless context (component tests): no toast to queue.
        }

        return $this->redirectToRoute('admin_agents', [
            'adminPrefix' => $this->adminPrefix,
        ]);
    }

    private function prefill(): void
    {
        $agent = $this->getAgent();
        $this->firstName = $agent->firstName ?? '';
        $this->lastName = $agent->lastName ?? '';
        $this->email = $agent->email ?? '';
        $this->phone = $agent->phone ?? '';
        $this->agencyId = $agent->agencyId ?? null;
        $this->note = $agent->note ?? '';
        $this->specialties = array_map(static fn (AgentSpecialty $s): string => $s->value, $agent->specialties ?? []);
        $this->professionalCards = array_map(static fn (ProfessionalCard $c): string => $c->value, $agent->professionalCards ?? []);
        $this->position = $agent->position->value ?? '';
        $this->address = $agent->address ?? '';
        $this->areas = $agent->areas ?? '';
        $this->addressLat = $agent->latitude ?? null;
        $this->addressLng = $agent->longitude ?? null;
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

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_AGENTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }

    /** Instantané du staff courant (nom, sinon email) pour la traçabilité. */
    private function currentStaffName(): ?string
    {
        $user = $this->security->getUser();
        if (!$user instanceof \App\Auth\Entity\User) {
            return null;
        }
        $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));

        return '' !== $fullName ? $fullName : (string) $user->getEmail();
    }

    /** Instantané de la photo de profil du staff courant. */
    private function currentStaffAvatar(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof \App\Auth\Entity\User ? $user->getAvatarFilename() : null;
    }
}
