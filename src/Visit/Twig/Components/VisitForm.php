<?php

declare(strict_types=1);

namespace App\Visit\Twig\Components;

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

/**
 * Quick visit booking form: only the essential fields up front, the rest
 * behind three collapsible sections (contacts, property details, photos).
 * Address coordinates come from the Places
 * autocomplete (chooseLocation); creation falls back to server geocoding when
 * the address was typed by hand.
 */
#[AsLiveComponent(name: 'Visit:VisitForm', template: 'components/Visit/VisitForm.html.twig')]
final class VisitForm extends AbstractController
{
    use VisitsSectionGuard;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

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

    /**
     * Référence du dossier choisi, miroir dans l'URL (?dossier=DS-XXXXXX) :
     * l'URL du formulaire se partage avec le dossier déjà présélectionné
     * (le contrôleur de la page résout la référence au chargement).
     */
    #[LiveProp(url: true)]
    public ?string $dossier = null;

    /**
     * Collapsible optional sections, all closed by default. Live props (not
     * native <details>): the morph would lose the open state on re-render.
     */
    #[LiveProp]
    public bool $contactsOpen = false;

    #[LiveProp]
    public bool $propertyDetailsOpen = false;

    #[LiveProp]
    public bool $photosOpen = false;

    /** Complément du récap (agent, annonce, présence, note) déplié. */
    #[LiveProp]
    public bool $recapMoreOpen = false;

    /**
     * Modale de confirmation avant création : ouverte par askCreate une fois
     * le formulaire valide, refermée par cancelCreate ou par create. Animée
     * comme les modales du BO, ce qui reste sûr car la checkbox "prévenir le
     * client" est en data-model norender : la modale ne se re-rend jamais
     * ouverte (le morph ne retire donc pas l'attribut d'animation).
     */
    #[LiveProp]
    public bool $confirmingCreate = false;

    /** Envoi de l'email de confirmation au contact principal du dossier. */
    #[LiveProp(writable: true)]
    public bool $notifyClient = true;

    /**
     * Dernier type de visite vu par la présélection de durée : permet de ne
     * re-préremplir la durée que si l'utilisateur n'y a pas touché (elle vaut
     * encore le défaut du type précédent).
     */
    #[LiveProp]
    public string $syncedType = 'property_visit';

    /**
     * Dernière durée écrite par la présélection elle-même : seule une durée
     * encore égale à cette valeur peut être re-préremplie. Compare à la
     * valeur réellement posée (et pas au défaut du type précédent) : un
     * choix manuel qui coïncide avec le défaut d'un autre type (60 min
     * choisi à la main, puis type état des lieux) resterait sinon
     * indiscernable d'une présélection et se ferait écraser au retour.
     */
    #[LiveProp]
    public int $syncedDuration = 30;

    /** Durée estimée par défaut selon le type de visite (minutes). */
    private const DEFAULT_DURATIONS = [
        'property_visit' => 30,
        'inventory' => 60,
        'technical_intervention' => 60,
    ];

    public function __construct(
        private readonly Security $security,
        private readonly \App\Auth\Repository\UserRepository $users,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private readonly \App\Visit\Service\VisitNumberGenerator $numbers,
        private readonly \App\Visit\Repository\VisitRepository $visits,
        private readonly \App\Dossier\Repository\DossierRepository $dossiers,
        private readonly \App\RealEstateAgent\Repository\RealEstateAgentRepository $agents,
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly \App\Visit\Storage\VisitPhotoStorage $photoStorage,
        private readonly \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        private readonly \App\Dossier\Service\DossierEventLogger $events,
        private readonly \App\Visit\Service\VisitClientMailer $clientMailer,
        private readonly \App\Visit\Service\VisitCalendarSync $calendarSync,
    ) {
    }

    /** @var list<\App\RealEstateAgent\Domain\AgentPickerOption>|null */
    private ?array $agentOptionsCache = null;

    /**
     * Rich rows of the agent dropdown (avatar, name, agency and brand),
     * memoized: the template reads them for the panel and the summary.
     *
     * @return list<\App\RealEstateAgent\Domain\AgentPickerOption>
     */
    public function getAgentOptions(): array
    {
        return $this->agentOptionsCache ??= $this->agents->findPickerOptions();
    }

    public function getSelectedAgent(): ?\App\RealEstateAgent\Domain\AgentPickerOption
    {
        $id = (int) ($this->formValues['agent'] ?? 0);
        if (0 === $id) {
            return null;
        }
        foreach ($this->getAgentOptions() as $option) {
            if ($option->id === $id) {
                return $option;
            }
        }

        return null;
    }

    /** Sélection dans le dropdown agent (null ou re-clic = retirer). */
    #[LiveAction]
    public function chooseAgent(#[LiveArg] ?int $id = null): void
    {
        $this->ensureAdmin();

        if (null === $id) {
            $this->formValues['agent'] = '';

            return;
        }
        foreach ($this->getAgentOptions() as $option) {
            if ($option->id === $id) {
                $this->formValues['agent'] = (int) ($this->formValues['agent'] ?? 0) === $id ? '' : (string) $id;

                return;
            }
        }

        throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unknown agent.');
    }

    /**
     * Mode édition : le composant est monté sur une visite déjà persistée
     * (page "Modifier"). Le formulaire est le même qu'à la création, mais le
     * dossier est verrouillé (changer de dossier = autre visite), la section
     * photos est masquée (les photos se gèrent sur la fiche) et create()
     * devient une mise à jour sans nouvel événement dossier ni email.
     */
    public function isEditing(): bool
    {
        return null !== $this->visit?->getId();
    }

    /** Référence de la visite éditée (lien "Annuler" vers la fiche). */
    public function getEditedReference(): ?string
    {
        return $this->visit?->getReference();
    }

    public function mount(?int $dossierId = null): void
    {
        $this->ensureAdmin();
        $this->visit ??= new Visit();
        // Arrivée depuis une fiche dossier ("Planifier une visite") : le
        // dossier est présélectionné, le reste du formulaire s'ouvre direct.
        if (null !== $dossierId && null === $this->visit->getDossier()) {
            $dossier = $this->dossiers->find($dossierId);
            // Un dossier clôturé ne se présélectionne pas (lien périmé ou
            // référence forgée) : le formulaire retombe sur l'accueil.
            if (null !== $dossier && null === $dossier->getClosedAt()) {
                $this->visit->setDossier($dossier);
                $this->dossier = (string) $dossier->getReference();
            }
        }
    }

    protected function instantiateForm(): FormInterface
    {
        $this->visit ??= new Visit();

        // Arrivée depuis une fiche dossier : le dossier présélectionné doit
        // rester dans les choix même recherche incomplète, pour que le
        // bandeau d'explication puisse se rendre (le picker libre, lui, ne
        // liste que les dossiers à recherche complète).
        return $this->createForm(VisitType::class, $this->visit, [
            'preselected_dossier' => $this->visit->getDossier(),
            // Édition : dossier désactivé (valeur soumise ignorée par le
            // form) et contrainte "pas dans le passé" levée, re-vérifiée
            // dans create() uniquement si le créneau a bougé.
            'editing' => $this->isEditing(),
        ]);
    }

    /**
     * Fired when a suggestion of the places-autocomplete dropdown is picked
     * (same Places flow as the public listing form): the browser already has
     * the coordinates, no server-side geocoding involved.
     */
    #[LiveAction]
    public function chooseLocation(#[LiveArg] ?float $lat = null, #[LiveArg] ?float $lng = null): void
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

    /** Chevron toggles of the optional sections; kept out of set*() naming. */
    #[LiveAction]
    public function toggleContacts(): void
    {
        $this->ensureAdmin();

        $this->contactsOpen = !$this->contactsOpen;
    }

    #[LiveAction]
    public function togglePropertyDetails(): void
    {
        $this->ensureAdmin();

        $this->propertyDetailsOpen = !$this->propertyDetailsOpen;
    }

    #[LiveAction]
    public function togglePhotos(): void
    {
        $this->ensureAdmin();

        $this->photosOpen = !$this->photosOpen;
    }

    /** "Voir plus" du récap : déplie le complément (agent, annonce, note). */
    #[LiveAction]
    public function toggleRecapMore(): void
    {
        $this->ensureAdmin();

        $this->recapMoreOpen = !$this->recapMoreOpen;
    }

    /** Single-select chips with toggle-off, dossier-search vocabulary. */
    #[LiveAction]
    public function choosePropertyKind(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();

        if (null === \App\PropertyListing\Domain\PropertyType::tryFrom($value)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(\sprintf('Unknown property kind "%s".', $value));
        }
        $this->formValues['propertyKind'] = ($this->formValues['propertyKind'] ?? '') === $value ? '' : $value;
    }

    #[LiveAction]
    public function chooseFurnishing(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();

        if (null === \App\Contact\Domain\Furnishing::tryFrom($value)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(\sprintf('Unknown furnishing "%s".', $value));
        }
        $this->formValues['furnishing'] = ($this->formValues['furnishing'] ?? '') === $value ? '' : $value;
    }

    /** Coordonnées fiables : la sélection Places décrit l'adresse courante. */
    public function hasLocatedAddress(): bool
    {
        $address = trim((string) ($this->formValues['address'] ?? ''));

        return null !== $this->previewLat && null !== $this->previewLng
            && '' !== $this->locatedAddress && $address === $this->locatedAddress;
    }

    /** Chips Loyer HC / CC : en CC le champ Charges disparaît. */
    #[LiveAction]
    public function chooseRentMode(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();
        if (!\in_array($value, ['hc', 'cc'], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unknown rent mode.');
        }
        $this->formValues['rentChargesIncluded'] = 'cc' === $value ? '1' : '';
        if ('cc' === $value) {
            $this->formValues['charges'] = '';
        }
    }

    #[LiveAction]
    public function chooseLeaseType(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();

        if (null === \App\Visit\Domain\LeaseType::tryFrom($value)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(\sprintf('Unknown lease type "%s".', $value));
        }
        $this->formValues['leaseType'] = ($this->formValues['leaseType'] ?? '') === $value ? '' : $value;
    }

    /**
     * Visit-agent chips of the "performed by" picker.
     *
     * @return list<array{id: int, name: string, avatar: ?string}>
     */
    public function getAssigneeChoices(): array
    {
        return array_map(
            static fn (\App\Auth\Entity\User $user): array => [
                'id' => (int) $user->getId(),
                'name' => trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? '')) ?: (string) $user->getEmail(),
                'avatar' => $user->getAvatarFilename(),
            ],
            $this->users->findVisitAgents(),
        );
    }

    private bool $selectedDossierResolved = false;
    private ?\App\Dossier\Domain\DossierSummary $selectedDossierCache = null;

    /**
     * Dossier choisi dans le premier champ : tant qu'il est vide, le reste du
     * formulaire ne s'affiche pas (une visite sans dossier n'existe pas).
     * Mémoïsé : le template et l'alerte hors secteur le lisent dans le même
     * rendu.
     */
    public function getSelectedDossier(): ?\App\Dossier\Domain\DossierSummary
    {
        if (!$this->selectedDossierResolved) {
            $id = (int) ($this->formValues['dossier'] ?? 0);
            $this->selectedDossierCache = 0 !== $id ? $this->dossiers->findSummaryById($id) : null;
            $this->selectedDossierResolved = true;
        }

        return $this->selectedDossierCache;
    }

    /** @var list<\App\Visit\Domain\VisitSummary>|null */
    private ?array $assigneeConflictsCache = null;

    /**
     * Conflit d'agenda de la personne désignée : ses visites non annulées qui
     * chevauchent le créneau saisi. Informatif, jamais bloquant (deux visites
     * proches restent parfois voulues).
     *
     * @return list<\App\Visit\Domain\VisitSummary>
     */
    public function getAssigneeConflicts(): array
    {
        return $this->assigneeConflictsCache ??= $this->computeAssigneeConflicts();
    }

    /** @return list<\App\Visit\Domain\VisitSummary> */
    private function computeAssigneeConflicts(): array
    {
        $assigneeId = (int) ($this->formValues['assignee'] ?? 0);
        $scheduledAt = $this->parseScheduledAt();
        if (0 === $assigneeId || null === $scheduledAt) {
            return [];
        }

        $duration = max(1, (int) ($this->formValues['durationMinutes'] ?? 30));

        return $this->visits->findAssigneeConflicts($assigneeId, $scheduledAt, $duration, $this->visit?->getId());
    }

    private bool $outOfAreaResolved = false;

    /** @var array{district: string, areas: list<string>}|null */
    private ?array $outOfAreaCache = null;

    /**
     * Alerte hors secteur : le bien géolocalisé (sélection Places) tombe dans
     * un quartier que la recherche du dossier ne vise pas. Null si pas de
     * coordonnées fiables, pas de quartiers recherchés, recherche "tous les
     * arrondissements" ou point hors de tous les polygones.
     *
     * @return array{district: string, areas: list<string>}|null labels prêts à afficher
     */
    public function getOutOfArea(): ?array
    {
        if (!$this->outOfAreaResolved) {
            $this->outOfAreaCache = $this->computeOutOfArea();
            $this->outOfAreaResolved = true;
        }

        return $this->outOfAreaCache;
    }

    /** @return array{district: string, areas: list<string>}|null */
    private function computeOutOfArea(): ?array
    {
        $areasCsv = trim((string) $this->getSelectedDossier()?->searchAreas);
        if ('' === $areasCsv || \App\Contact\Domain\ParisDistricts::allArrondissementsSelected($areasCsv)) {
            return null;
        }

        // Coordonnées valables uniquement si elles décrivent l'adresse
        // courante (sélection Places non retouchée à la main).
        $address = trim((string) ($this->formValues['address'] ?? ''));
        if (null === $this->previewLat || null === $this->previewLng
            || '' === $this->locatedAddress || $address !== $this->locatedAddress) {
            return null;
        }

        $code = \App\Contact\Domain\DistrictLocator::locate($this->previewLat, $this->previewLng);
        if (null === $code) {
            return null;
        }

        $selected = array_values(array_filter(array_map(trim(...), explode(',', $areasCsv))));
        if (\in_array($code, $selected, true)) {
            return null;
        }

        $labels = \App\Contact\Domain\ParisDistricts::LABELS;

        return [
            'district' => $labels[$code] ?? $code,
            'areas' => array_map(static fn (string $c): string => $labels[$c] ?? $c, $selected),
        ];
    }

    /**
     * Créneau improbable (avant 08:00 ou à partir de 20:00) : simple rappel
     * visuel, aucune garde serveur.
     */
    public function isOddHourSlot(): bool
    {
        $scheduledAt = $this->parseScheduledAt();
        if (null === $scheduledAt) {
            return false;
        }
        $hour = (int) $scheduledAt->format('G');

        return $hour < 8 || $hour >= 20;
    }

    /** Créneau saisi (format datetime-local Y-m-d\TH:i), null si invalide. */
    private function parseScheduledAt(): ?\DateTimeImmutable
    {
        $raw = trim((string) ($this->formValues['scheduledAt'] ?? ''));
        if ('' === $raw) {
            return null;
        }
        // "!" remet à zéro les champs absents du format (secondes,
        // microsecondes) : sans lui, les secondes de l'horloge courante se
        // glissent dans le créneau et deux visites dos à dos se chevauchent
        // de quelques secondes fantômes.
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw)
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $raw);

        return false !== $parsed ? $parsed : null;
    }

    /**
     * Présélection de la durée estimée selon le type de visite, à chaque
     * re-rendu live (les chips type sont des radios natives, sans action
     * dédiée). Un choix explicite de durée est conservé : on ne re-préremplit
     * que si la durée courante vaut encore le défaut du type précédent.
     */
    /** Miroir URL : la référence suit le dossier choisi dans le formulaire. */
    #[\Symfony\UX\LiveComponent\Attribute\PostHydrate]
    public function syncDossierUrl(): void
    {
        $this->dossier = $this->getSelectedDossier()?->reference;
    }

    #[\Symfony\UX\LiveComponent\Attribute\PostHydrate]
    public function syncDurationWithType(): void
    {
        $type = (string) ($this->formValues['type'] ?? '');
        if ('' === $type || $type === $this->syncedType) {
            return;
        }

        if ((int) ($this->formValues['durationMinutes'] ?? 0) === $this->syncedDuration) {
            $default = self::DEFAULT_DURATIONS[$type] ?? 30;
            $this->formValues['durationMinutes'] = (string) $default;
            $this->syncedDuration = $default;
        }
        $this->syncedType = $type;
    }

    /**
     * Visites déjà prévues sur le même bien (même adresse ou même lien
     * d'annonce), recalculées à chaque re-rendu du formulaire.
     *
     * @return list<\App\Visit\Domain\VisitSummary>
     */
    public function getMatchingVisits(): array
    {
        $address = trim((string) ($this->formValues['address'] ?? ''));
        $listingUrl = trim((string) ($this->formValues['listingUrl'] ?? ''));
        // Une adresse à peine commencée matcherait n'importe quoi.
        if (mb_strlen($address) < 5) {
            $address = '';
        }

        return $this->visits->findMatchingSummaries($address, $listingUrl);
    }

    /** Chip toggle: picking the selected agent deselects them. */
    #[LiveAction]
    public function pickAssignee(#[LiveArg] int $id): void
    {
        $this->ensureAdmin();

        $current = (int) ($this->formValues['assignee'] ?? 0);
        $this->formValues['assignee'] = $current === $id ? '' : (string) $id;
    }

    /**
     * Ouvre la modale de confirmation une fois le formulaire valide : la
     * validation rejoue submitForm, donc un POST invalide re-rend les
     * erreurs de champ sans jamais ouvrir la modale. Les gardes métier de
     * create (dossier clos, double-booking, IDF...) restent au confirm : si
     * l'une échoue, la modale se referme et l'erreur s'affiche en place.
     */
    #[LiveAction]
    public function askCreate(): void
    {
        $this->ensureAdmin();

        $this->submitForm();

        $this->confirmingCreate = true;
    }

    /** Unique chemin de sortie de la modale de confirmation. */
    #[LiveAction]
    public function cancelCreate(): void
    {
        $this->ensureAdmin();

        $this->confirmingCreate = false;
    }

    #[LiveAction]
    public function create(EntityManagerInterface $em, AddressGeocoder $geocoder): RedirectResponse
    {
        $this->ensureAdmin();

        // Quelle que soit l'issue, la modale ne survit pas au confirm : un
        // échec de garde re-rend le formulaire avec l'erreur, pas la modale.
        $this->confirmingCreate = false;

        // Édition : les valeurs stockées (avant soumission) servent aux
        // gardes "seulement si ça a bougé" (créneau passé, freeBusy qui voit
        // le miroir agenda de la visite elle-même sur son ancien slot).
        $editing = $this->isEditing();
        $originalScheduledAt = $editing ? $this->visit?->getScheduledAt() : null;
        $originalDuration = $editing ? $this->visit?->getDurationMinutes() : null;

        // Throws UnprocessableEntityHttpException on invalid input — the
        // component re-renders with the field errors.
        $this->submitForm();

        /** @var Visit $visit */
        $visit = $this->getForm()->getData();

        // Édition : la contrainte "pas dans le passé" est levée au niveau du
        // formulaire (une visite passée doit rester éditable), mais déplacer
        // le créneau VERS le passé reste refusé.
        $scheduledAt = $visit->getScheduledAt();
        if ($editing && null !== $scheduledAt
            && $scheduledAt->format('Y-m-d H:i') !== $originalScheduledAt?->format('Y-m-d H:i')
            && $scheduledAt < new \DateTimeImmutable('-10 minutes')) {
            $this->getForm()->get('scheduledAt')->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('admin.visits.create.scheduledAt.past'),
            ));

            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('The slot cannot move into the past.');
        }

        // Un dossier clôturé ne reçoit plus de visite : le picker libre
        // l'exclut déjà, cette garde couvre la présélection périmée (lien
        // "Planifier une visite" gardé ouvert pendant la clôture) et le
        // POST forgé sur le endpoint /_components/. En édition la question
        // ne se pose pas : le dossier est verrouillé et la visite existe.
        if (!$editing && null !== $visit->getDossier()?->getClosedAt()) {
            $this->getForm()->get('dossier')->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('admin.visits.create.dossier.closed'),
            ));

            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Dossier is closed.');
        }

        // Une visite se prépare sur les critères de recherche du dossier :
        // tant qu'ils sont incomplets, il n'y a rien pour cadrer le bien à
        // visiter. Le template masque déjà le formulaire, cette garde couvre
        // l'appel direct au endpoint /_components/. En édition la visite a
        // déjà été cadrée à la réservation : pas de re-vérification.
        if (!$editing && !$this->isSearchComplete($visit->getDossier())) {
            $this->getForm()->get('dossier')->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('admin.visits.create.dossier.incomplete'),
            ));

            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Dossier search criteria incomplete.');
        }

        // Formule Accompagné : le client visite pour son propre compte, donc
        // jamais d'assigné d'équipe et sa présence va de soi (une valeur
        // forgée dans le POST est écrasée). Uniquement pour une visite de
        // bien : un état des lieux ou une intervention technique reste porté
        // par l'équipe.
        if ('accompagne' === $visit->getDossier()?->getOffer()
            && \App\Visit\Domain\VisitType::PropertyVisit === $visit->getType()) {
            $visit->setAssignee(null);
            $visit->setClientPresent(true);
        }

        // État des lieux et intervention technique : le client ne peut pas
        // les réaliser seul, un membre de l'équipe doit être désigné.
        if (null === $visit->getAssignee()
            && \in_array($visit->getType(), [\App\Visit\Domain\VisitType::Inventory, \App\Visit\Domain\VisitType::TechnicalIntervention], true)) {
            $this->getForm()->get('assignee')->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('admin.visits.create.assignee.requiredForType'),
            ));

            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('This visit type needs a team member.');
        }

        // Loyer charges comprises : le montant de charges séparé n'a plus
        // d'objet, un reliquat saisi avant la bascule est abandonné.
        if (true === $visit->getRentChargesIncluded()) {
            $visit->setCharges(null);
        }

        // Vrai double-booking (même dossier, même adresse, même jour, visite
        // non annulée) : refusé net; le bandeau doublon reste informatif pour
        // les autres cas (autre dossier, autre jour). En édition la visite
        // elle-même est exclue (déplacer son propre créneau reste possible).
        if (null !== $scheduledAt && $this->visits->hasSameDossierSameDayVisit(
            (int) $visit->getDossier()?->getId(),
            trim((string) $visit->getAddress()),
            $scheduledAt,
            $visit->getId(),
        )) {
            $this->getForm()->get('address')->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('admin.visits.create.duplicate.blocked'),
            ));

            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Same dossier already books this address that day.');
        }

        // L'assigné doit être libre sur le créneau. Deux niveaux, bloquants
        // tous les deux : ses visites en base d'abord (le bandeau ambre live
        // reste l'avertissement précoce), puis son agenda Google complet
        // (visio de lead, rendez-vous perso...) via freeBusy. L'API en panne
        // ou non configurée ne bloque jamais : seul le contrôle base tient.
        $assignee = $visit->getAssignee();
        if (null !== $assignee && null !== $scheduledAt) {
            $conflicts = $this->visits->findAssigneeConflicts((int) $assignee->getId(), $scheduledAt, $visit->getDurationMinutes(), $visit->getId());
            if ([] !== $conflicts) {
                $conflict = $conflicts[0];
                $this->getForm()->get('assignee')->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('admin.visits.create.assignee.overlap', [
                        '%name%' => (string) $conflict->assigneeName,
                        '%start%' => $conflict->scheduledAt->format('d/m/Y H:i'),
                        '%end%' => $conflict->scheduledAt->modify(\sprintf('+%d minutes', max(1, $conflict->durationMinutes)))->format('H:i'),
                        '%reference%' => $conflict->reference,
                    ]),
                ));

                throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Assignee already has a visit on this slot.');
            }

            $assigneeEmail = trim((string) $assignee->getEmail());
            // Édition : le miroir agenda de la visite elle-même ressort busy
            // sur son ancien créneau, qui est donc toléré.
            $busy = '' !== $assigneeEmail
                ? $this->calendarSync->findAssigneeBusyInterval($assigneeEmail, $scheduledAt, $visit->getDurationMinutes(), $originalScheduledAt, $originalDuration)
                : null;
            if (null !== $busy) {
                $name = trim(($assignee->getFirstName() ?? '').' '.($assignee->getLastName() ?? '')) ?: $assigneeEmail;
                $this->getForm()->get('assignee')->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('admin.visits.create.assignee.busy', [
                        '%name%' => $name,
                        '%start%' => $busy['start']->format('d/m/Y H:i'),
                        '%end%' => $busy['end']->format('H:i'),
                    ]),
                ));

                throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Assignee is busy on this slot (Google agenda).');
            }
        }

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

        // Property visits only happen in Île-de-France: coordinates
        // checked against the region's bounding box when available,
        // postal-code fallback otherwise.
        if (!$this->isInIleDeFrance($visit)) {
            $this->getForm()->get('address')->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('admin.visits.create.address.idf'),
            ));

            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Address outside Île-de-France.');
        }

        // ── Édition : l'entité est déjà managée, pas de nouvelle référence
        // ni de bookedBy; instantané modificateur + miroir agenda, puis
        // retour sur la fiche. Ni événement dossier, ni email client, ni
        // photos (elles se gèrent sur la fiche). ──
        if ($editing) {
            $user = $this->security->getUser();
            if ($user instanceof \App\Auth\Entity\User) {
                $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));
                $visit->touchBy('' !== $fullName ? $fullName : (string) $user->getEmail(), $user->getAvatarFilename());
            }
            $em->flush();

            // Miroir Google Calendar, best-effort : les ids posés sont
            // flushés dans la foulée.
            $this->calendarSync->sync($visit);
            $em->flush();

            try {
                $this->addFlash('success', 'admin.toast.saved');
            } catch (\LogicException) {
                // Sessionless context (component tests): no toast to queue.
            }

            return $this->redirectToRoute('admin_visit_show', [
                'adminPrefix' => $this->adminPrefix,
                'reference' => (string) $visit->getReference(),
            ]);
        }

        $visit->setCreatedAt(new \DateTimeImmutable());
        $visit->setReference($this->numbers->reference());
        $user = $this->security->getUser();
        if ($user instanceof \App\Auth\Entity\User) {
            $visit->setBookedBy($user);
        }
        $em->persist($visit);
        // L'entrée du fil de suivi part dans la même transaction que la
        // visite (le logger persiste sans flusher).
        $dossier = $visit->getDossier();
        if (null !== $dossier) {
            $this->events->log($dossier, 'visit_booked', [
                'value' => trim((string) $visit->getAddress()),
                'date' => $visit->getScheduledAt()?->format('d/m/Y H:i') ?? '',
            ]);
        }
        $em->flush();

        // Property photos sent along with the action ("files|create"): the
        // reference now exists, the storage prefix visits/<ref>/photos/ is
        // known. Best effort, like the agent photo: an unreadable file never
        // blocks the visit.
        $this->storeUploadedPhotos($visit, $em);

        // Miroir Google Calendar (agenda central + agenda de l'assigné),
        // best-effort : la visite est déjà créée, un échec ne bloque rien.
        $this->calendarSync->sync($visit);
        $em->flush();

        // Email de confirmation au contact principal du dossier, si demandé
        // dans la modale. Best-effort : le mailer avale les échecs de
        // transport (loggés en warning), la visite est déjà créée.
        if ($this->notifyClient) {
            $this->clientMailer->send($visit);
        }

        try {
            $this->addFlash('success', 'admin.toast.visitPlanned');
        } catch (\LogicException) {
            // Sessionless context (component tests): no toast to queue.
        }

        return $this->redirectToRoute('admin_visits', [
            'adminPrefix' => $this->adminPrefix,
        ]);
    }

    /** Maximum number of photos attached at creation. */
    public const MAX_PHOTOS = 12;

    /**
     * Stores the "photos[]" file inputs of the create action into the visit
     * photo storage, same validation as the visit-page upload (jpeg/png/webp,
     * 10 MB each, 12 photos max). Invalid or extra files are silently
     * skipped: photos are a nice-to-have, never a booking blocker.
     */
    private function storeUploadedPhotos(Visit $visit, EntityManagerInterface $em): void
    {
        $files = $this->requestStack->getCurrentRequest()?->files->all('photos') ?? [];
        if ([] === $files) {
            return;
        }

        $constraint = new \Symfony\Component\Validator\Constraints\Image(
            maxSize: '10M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        );

        $stored = 0;
        foreach ($files as $file) {
            if ($stored >= self::MAX_PHOTOS) {
                break;
            }
            if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
                || $this->validator->validate($file, $constraint)->count() > 0) {
                continue;
            }

            try {
                $photo = (new \App\Visit\Entity\VisitPhoto())
                    ->setOriginalName((string) $file->getClientOriginalName())
                    ->setMimeType((string) $file->getMimeType())
                    ->setCreatedAt(new \DateTimeImmutable())
                    // À la création, les photos décrivent le bien avant la
                    // visite (annonce, agent immobilier).
                    ->setPhase('before')
                    ->setPath($this->photoStorage->store((string) $visit->getReference(), $file));
            } catch (\Throwable) {
                continue;
            }
            $visit->addPhoto($photo);
            $em->persist($photo);
            ++$stored;
        }

        if ($stored > 0) {
            $em->flush();
        }
    }

    /**
     * Discreet property line of the side recap, only the entered values:
     * "45 m² · 6e étage · 1 450 € HC + 150 €".
     *
     * @return list<string>
     */
    public function getPropertyRecapParts(): array
    {
        $recap = $this->computePropertyRecap();

        return [...$recap['facts'], ...(null !== $recap['rent'] ? [$recap['rent']] : [])];
    }

    /**
     * Caractéristiques du bien sans le loyer (ligne discrète du récap).
     *
     * @return list<string>
     */
    public function getRecapFacts(): array
    {
        return $this->computePropertyRecap()['facts'];
    }

    /** Loyer formaté ("1 450 € HC + 150 €"), mis en avant dans le récap. */
    public function getRecapRent(): ?string
    {
        return $this->computePropertyRecap()['rent'];
    }

    /** @var array{facts: list<string>, rent: string|null}|null */
    private ?array $propertyRecapCache = null;

    /** @return array{facts: list<string>, rent: string|null} */
    private function computePropertyRecap(): array
    {
        if (null !== $this->propertyRecapCache) {
            return $this->propertyRecapCache;
        }
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'fr';
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $number = static function (string $raw) use ($formatter): ?string {
            $normalized = str_replace(',', '.', trim($raw));
            if ('' === $normalized || !is_numeric($normalized)) {
                return null;
            }

            return (string) $formatter->format((float) $normalized);
        };

        $parts = [];
        if (null !== ($surface = $number((string) ($this->formValues['surface'] ?? '')))) {
            $parts[] = $this->translator->trans('admin.visits.create.propertyDetails.recap.surface', ['%surface%' => $surface]);
        }

        $floorRaw = trim((string) ($this->formValues['floor'] ?? ''));
        if ('' !== $floorRaw && is_numeric($floorRaw)) {
            $parts[] = 0 === (int) $floorRaw
                ? $this->translator->trans('admin.visits.create.propertyDetails.recap.groundFloor')
                : $this->translator->trans('admin.visits.create.propertyDetails.recap.floor', ['%floor%' => (int) $floorRaw]);
        }

        // Les caractéristiques choisies (type, meublé, bail) ouvrent la ligne.
        foreach ([
            ['propertyKind', 'listProperty.form.propertyType.choice.'],
            ['furnishing', null],
            ['leaseType', null],
        ] as [$key, $prefix]) {
            $value = trim((string) ($this->formValues[$key] ?? ''));
            if ('' === $value) {
                continue;
            }
            $labelKey = match ($key) {
                'propertyKind' => $prefix.$value,
                'furnishing' => \App\Contact\Domain\Furnishing::tryFrom($value)?->labelKey(),
                'leaseType' => \App\Visit\Domain\LeaseType::tryFrom($value)?->labelKey(),
            };
            if (null !== $labelKey) {
                $parts[] = $this->translator->trans($labelKey);
            }
        }

        $rent = $number((string) ($this->formValues['rentExcludingCharges'] ?? ''));
        $rentCc = '1' === ($this->formValues['rentChargesIncluded'] ?? '');
        $charges = $rentCc ? null : $number((string) ($this->formValues['charges'] ?? ''));
        if (null !== $rent) {
            $part = $this->translator->trans($rentCc ? 'admin.visits.create.propertyDetails.recap.rentCc' : 'admin.visits.create.propertyDetails.recap.rent', ['%amount%' => $rent]);
            if (null !== $charges) {
                $part .= ' '.$this->translator->trans('admin.visits.create.propertyDetails.recap.charges', ['%amount%' => $charges]);
            }
            $rentLine = $part;
        } elseif (null !== $charges) {
            $rentLine = $this->translator->trans('admin.visits.create.propertyDetails.recap.chargesAlone', ['%amount%' => $charges]);
        } else {
            $rentLine = null;
        }

        return $this->propertyRecapCache = ['facts' => $parts, 'rent' => $rentLine];
    }

    private function isSearchComplete(?\App\Dossier\Entity\Dossier $dossier): bool
    {
        return $dossier?->getSearch()?->isComplete() ?? false;
    }

    private function isInIleDeFrance(Visit $visit): bool
    {
        $lat = $visit->getLatitude();
        $lng = $visit->getLongitude();
        if (null !== $lat && null !== $lng) {
            return $lat >= 48.12 && $lat <= 49.242 && $lng >= 1.446 && $lng <= 3.559;
        }

        return 1 === preg_match('/\b(75|77|78|91|92|93|94|95)\d{3}\b/', (string) $visit->getAddress());
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_VISITS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
