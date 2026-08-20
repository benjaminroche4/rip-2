<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Contact\Domain\Furnishing;
use App\Contact\Domain\GuarantorType;
use App\Contact\Domain\ParisDistricts;
use App\Contact\Domain\StayDuration;
use App\Dossier\Domain\AffordableRent;
use App\Dossier\Domain\CsvSelection;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\DossierStep;
use App\Dossier\Domain\ImportantAddressList;
use App\Dossier\Domain\LeaseCompatibility;
use App\Dossier\Domain\SearchAutosave;
use App\Dossier\Domain\SearchCriterion;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierSearch;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\DossierProgressCalculator;
use App\Dossier\Service\DossierStatusAdvancer;
use App\Dossier\Service\DossierStepValidator;
use App\Dossier\Service\SearchCriterionToggler;
use App\PropertyListing\Domain\Amenity;
use App\PropertyListing\Domain\PropertyType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Point;

/**
 * Editable "Recherche" card on the dossier detail page: same fields and
 * autosave flow as Admin:ContactProject, persisted on the dossier's own
 * DossierSearch row (a snapshot: edits never touch the source contact).
 *
 * The component only orchestrates the live props and actions; the chip
 * toggle semantics, autosave normalization and address list rules live in
 * the Dossier domain (SearchCriterion, SearchAutosave, ImportantAddressList).
 */
#[AsLiveComponent(name: 'Dossier:Search', template: 'components/Dossier/Search.html.twig')]
final class SearchEditor
{
    use DossiersSectionGuard;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public const MAX_IMPORTANT_ADDRESSES = ImportantAddressList::MAX;

    #[LiveProp]
    public int $dossierId = 0;

    /**
     * Fold state of the card. It has to live on the server: the browser's own
     * `open` attribute is restored by the morph at every re-render (autosave),
     * so a card collapsed by hand would pop back open on the next keystroke.
     */
    #[LiveProp]
    public bool $expanded = true;

    #[LiveProp(writable: true)]
    public string $budget = '';

    #[LiveProp(writable: true)]
    public string $areas = '';

    /** "Y-m-d" (date input format), '' = none. */
    #[LiveProp(writable: true)]
    public string $moveInAt = '';

    #[LiveProp(writable: true)]
    public string $propertyType = '';

    /** Free-form note, same read-mode/pencil flow as the contact project. */
    #[LiveProp(writable: true)]
    public string $note = '';

    #[LiveProp]
    public bool $editingNote = false;

    /**
     * Anti-missclick shield: fields start locked on every page load and
     * only mutate once explicitly unlocked via the padlock.
     */
    #[LiveProp]
    public bool $locked = true;

    /** Draft of the "important address" row being added. */
    #[LiveProp(writable: true)]
    public string $addressDraft = '';

    #[LiveProp(writable: true)]
    public string $addressTypeDraft = 'work';

    /**
     * Coordinates of the draft address, filled by the Places selection so
     * the row can be pinned on the districts map ('' when typed free-form:
     * the front geocodes those as a fallback).
     */
    #[LiveProp(writable: true)]
    public string $addressLatDraft = '';

    #[LiveProp(writable: true)]
    public string $addressLngDraft = '';

    /** Household typology of the project ('' = unspecified). */
    #[LiveProp]
    public string $householdType = '';

    /** Minimum surface in m², '' = none (saved with the main autosave). */
    #[LiveProp(writable: true)]
    public string $minSurface = '';

    /**
     * "+N" équipements dépliés. L'état vit sur le serveur : chaque sélection
     * re-rend la card, un dépliage purement DOM (controller reveal) serait
     * refermé par le morph au premier clic. Déplié, le menu reste ouvert pour
     * enchaîner les sélections.
     */
    #[LiveProp]
    public bool $equipmentExpanded = false;

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly DossierStatusAdvancer $advancer,
        private readonly DossierProgressCalculator $progress,
        private readonly SearchCriterionToggler $toggler,
        private readonly DossierStepValidator $stepValidator,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ) {
    }

    public function mount(int $dossierId): void
    {
        $this->ensureAdmin();
        $this->dossierId = $dossierId;

        $search = $this->dossier()->getSearch();
        $this->budget = null !== $search?->getBudget() ? (string) $search->getBudget() : '';
        $this->areas = $search?->getAreas() ?? '';
        $this->moveInAt = $search?->getMoveInAt()?->format('Y-m-d') ?? '';
        $this->propertyType = $search?->getPropertyType() ?? '';
        $this->note = $search?->getNote() ?? '';
        $this->editingNote = '' === $this->note;
        $this->householdType = $search?->getHouseholdType() ?? '';
        $this->minSurface = null !== $search?->getMinSurface() ? (string) $search->getMinSurface() : '';
    }

    /** Drives the header badge: green check when every criterion is filled. */
    public function isComplete(): bool
    {
        return $this->dossier()->getSearch()?->isComplete() ?? false;
    }

    /** Centered between Paris and the petite couronne. */
    public function getMap(): Map
    {
        return (new Map('default'))
            ->center(new Point(48.8566, 2.3522))
            ->zoom(11.2)
            ->options(new GoogleOptions(
                gestureHandling: GestureHandling::COOPERATIVE,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
                zoomControl: false,
            ));
    }

    /**
     * Selected area chips; legacy free-text tokens show as-is.
     *
     * @return list<array{code: string, label: string}>
     */
    public function getAreaChips(): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'label' => ParisDistricts::LABELS[$code] ?? $code],
            array_values(array_filter(array_map(trim(...), explode(',', $this->areas)))),
        );
    }

    /** Active state of the "tous les arrondissements" pill above the map. */
    public function getAllArrondissementsSelected(): bool
    {
        return ParisDistricts::allArrondissementsSelected($this->areas);
    }

    /**
     * @return list<PropertyType>
     */
    public function getPropertyTypes(): array
    {
        return PropertyType::cases();
    }

    /**
     * @return list<string>
     */
    public function getSelectedPropertyTypes(): array
    {
        return CsvSelection::values($this->propertyType);
    }

    /**
     * One-click chips, multi-select. NOT named setPropertyType(): the
     * PropertyAccessor would call it during hydration of the writable prop.
     */
    #[LiveAction]
    public function togglePropertyType(#[LiveArg] string $type): void
    {
        $this->ensureAdmin();
        if ($this->isFrozen() || '' === $type) {
            return;
        }

        if (null === PropertyType::tryFrom($type)) {
            throw new BadRequestHttpException(\sprintf('Unknown property type "%s".', $type));
        }

        $this->propertyType = CsvSelection::toggle($this->propertyType, $type);
        $this->save();
    }

    /**
     * @return list<StayDuration>
     */
    public function getStayDurations(): array
    {
        return StayDuration::cases();
    }

    public function getCurrentStayDuration(): ?string
    {
        return $this->dossier()->getSearch()?->getStayDuration();
    }

    /** One-click chips, single-select with toggle-off on the active one. */
    #[LiveAction]
    public function chooseStayDuration(#[LiveArg] string $duration): void
    {
        $this->applyToggle(SearchCriterion::StayDuration, $duration);
    }

    /**
     * @return list<Furnishing>
     */
    public function getFurnishings(): array
    {
        return Furnishing::cases();
    }

    /**
     * @return list<string>
     */
    public function getSelectedFurnishings(): array
    {
        return CsvSelection::values($this->dossier()->getSearch()?->getFurnishing());
    }

    /** Multi-select: a tenant can be open to both. */
    #[LiveAction]
    public function chooseFurnishing(#[LiveArg] string $furnishing): void
    {
        $this->applyToggle(SearchCriterion::Furnishing, $furnishing);
    }

    /**
     * @return list<GuarantorType>
     */
    public function getGuarantorTypes(): array
    {
        return GuarantorType::cases();
    }

    /**
     * @return list<string>
     */
    public function getSelectedGuarantorTypes(): array
    {
        return $this->dossier()->getSearch()?->getGuarantorTypes() ?? [];
    }

    /** Multi-select: a household can combine e.g. physical and Garantme. */
    #[LiveAction]
    public function chooseGuarantorType(#[LiveArg] string $guarantor): void
    {
        $this->applyToggle(SearchCriterion::GuarantorType, $guarantor);
    }

    /**
     * @return list<string>
     */
    public function getGuarantorStatuses(): array
    {
        return SearchCriterion::GUARANTOR_STATUSES;
    }

    public function getCurrentGuarantorStatus(): ?string
    {
        return $this->dossier()->getSearch()?->getGuarantorStatus();
    }

    #[LiveAction]
    public function chooseGuarantorStatus(#[LiveArg] string $status): void
    {
        $this->applyToggle(SearchCriterion::GuarantorStatus, $status);
    }

    public function getCurrentOccupants(): ?int
    {
        return $this->dossier()->getSearch()?->getOccupants();
    }

    /**
     * Alerte discrète sous le champ occupants : un nombre d'occupants
     * renseigné mais inférieur au nombre de locataires de l'onglet Personnes
     * est forcément incohérent (chaque locataire occupe le logement).
     * Recalculée à chaque rendu, jamais bloquante.
     */
    public function getOccupantsMismatch(): bool
    {
        $occupants = $this->getCurrentOccupants();
        if (null === $occupants) {
            return false;
        }

        $tenants = 0;
        foreach ($this->dossier()->getPersons() as $person) {
            if (DossierPersonRole::TENANT === $person->getRole()) {
                ++$tenants;
            }
        }

        return $tenants > 0 && $occupants < $tenants;
    }

    #[LiveAction]
    public function chooseOccupants(#[LiveArg] int $count): void
    {
        $this->applyToggle(SearchCriterion::Occupants, $count);
    }

    /**
     * Required amenities: same referential as the "list my property" form.
     *
     * @return list<Amenity>
     */
    public function getEquipmentOptions(): array
    {
        return Amenity::cases();
    }

    /**
     * @return list<string>
     */
    public function getSelectedEquipment(): array
    {
        return CsvSelection::values($this->dossier()->getSearch()?->getEquipment());
    }

    #[LiveAction]
    public function chooseEquipment(#[LiveArg] string $equipment): void
    {
        $this->applyToggle(SearchCriterion::Equipment, $equipment);
    }

    /** Déplie la liste complète des équipements (pas de set* : hydratation). */
    #[LiveAction]
    public function revealEquipment(): void
    {
        $this->ensureAdmin();
        $this->equipmentExpanded = true;
    }

    /**
     * @return list<string>
     */
    public function getLeaseTypes(): array
    {
        return SearchCriterion::LEASE_TYPES;
    }

    /**
     * @return list<string>
     */
    public function getSelectedLeaseTypes(): array
    {
        return CsvSelection::values($this->dossier()->getSearch()?->getLeaseTypes());
    }

    /** Multi-select chips: a tenant can be open to several lease types. */
    #[LiveAction]
    public function chooseLeaseType(#[LiveArg] string $lease): void
    {
        $this->applyToggle(SearchCriterion::LeaseTypes, $lease);
    }

    /** Translation key of the lease/stay-duration mismatch, null when consistent. */
    public function getLeaseMismatch(): ?string
    {
        $search = $this->dossier()->getSearch();

        return null !== $search
            ? LeaseCompatibility::mismatchKey($search->getLeaseTypes(), $search->getStayDuration())
            : null;
    }

    #[LiveAction]
    public function toggleLock(): void
    {
        $this->ensureAdmin();
        $this->locked = !$this->locked;
    }

    /** Fires alongside the native <details> toggle, to keep the state. */
    #[LiveAction]
    public function toggleCard(): void
    {
        $this->ensureAdmin();
        $this->expanded = !$this->expanded;
    }

    /**
     * @return list<string>
     */
    public function getImportantAddressTypes(): array
    {
        return ImportantAddressList::TYPES;
    }

    /**
     * @return list<array{address: string, type: string, lat?: float, lng?: float}>
     */
    public function getImportantAddresses(): array
    {
        return $this->dossier()->getSearch()?->getImportantAddresses() ?? [];
    }

    /**
     * Custom dropdown of the draft row. NOT named setAddressTypeDraft():
     * the PropertyAccessor would call it during hydration.
     */
    #[LiveAction]
    public function chooseAddressType(#[LiveArg] string $type): void
    {
        $this->ensureAdmin();

        if ('' === $type) {
            return;
        }

        if (!\in_array($type, ImportantAddressList::TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown address type "%s".', $type));
        }

        $this->addressTypeDraft = $type;
    }

    #[LiveAction]
    public function addImportantAddress(): void
    {
        $this->ensureAdmin();
        if ($this->isFrozen()) {
            return;
        }

        if ('' === trim($this->addressDraft)) {
            return;
        }
        if (!\in_array($this->addressTypeDraft, ImportantAddressList::TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown address type "%s".', $this->addressTypeDraft));
        }

        $rows = ImportantAddressList::add(
            $this->getImportantAddresses(),
            $this->addressDraft,
            $this->addressTypeDraft,
            $this->addressLatDraft,
            $this->addressLngDraft,
        );
        if (null === $rows) {
            return;
        }

        $this->search()->setImportantAddresses($rows);
        $this->em->flush();

        $this->addressDraft = '';
        $this->addressLatDraft = '';
        $this->addressLngDraft = '';
        $this->dispatchBrowserEvent('dossier-search:saved');
    }

    #[LiveAction]
    public function removeImportantAddress(#[LiveArg] int $index): void
    {
        $this->ensureAdmin();
        if ($this->isFrozen()) {
            return;
        }

        $rows = ImportantAddressList::remove($this->getImportantAddresses(), $index);
        if (null === $rows) {
            return;
        }

        $this->search()->setImportantAddresses($rows);
        $this->em->flush();

        $this->dispatchBrowserEvent('dossier-search:saved');
    }

    public function getCurrentPets(): ?string
    {
        return $this->dossier()->getSearch()?->getPets();
    }

    /** Optional single-select, toggle-off on the active chip. */
    #[LiveAction]
    public function choosePets(#[LiveArg] string $value): void
    {
        $this->applyToggle(SearchCriterion::Pets, $value);
    }

    /**
     * @return list<string>
     */
    public function getHouseholdTypes(): array
    {
        return SearchCriterion::HOUSEHOLD_TYPES;
    }

    /** Optional custom dropdown: choosing persists immediately ('' = none). */
    #[LiveAction]
    public function chooseHouseholdType(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();
        if ($this->isFrozen()) {
            return;
        }

        if ('' !== $value && !\in_array($value, SearchCriterion::HOUSEHOLD_TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown household type "%s".', $value));
        }

        $this->householdType = $value;
        $this->search()->setHouseholdType('' !== $value ? $value : null);
        $this->commit();
    }

    public function getCurrentEarlyMoveIn(): ?string
    {
        return $this->dossier()->getSearch()?->getEarlyMoveIn();
    }

    /** Optional single-select: willing to take the flat before the date. */
    #[LiveAction]
    public function chooseEarlyMoveIn(#[LiveArg] string $value): void
    {
        $this->applyToggle(SearchCriterion::EarlyMoveIn, $value);
    }

    /** Chips 1..4 ("4+"), single-select with toggle-off. */
    public function getCurrentMinBedrooms(): ?int
    {
        return $this->dossier()->getSearch()?->getMinBedrooms();
    }

    #[LiveAction]
    public function chooseMinBedrooms(#[LiveArg] int $count): void
    {
        $this->applyToggle(SearchCriterion::MinBedrooms, $count);
    }

    public function getCurrentElevator(): ?string
    {
        return $this->dossier()->getSearch()?->getElevator();
    }

    /** Optional single-select, toggle-off on the active chip. */
    #[LiveAction]
    public function chooseElevator(#[LiveArg] string $value): void
    {
        $this->applyToggle(SearchCriterion::Elevator, $value);
    }

    /** Highest advisable monthly rent; null while no tenant income is known. */
    public function getMaxAffordableBudget(): ?int
    {
        return AffordableRent::maxBudget($this->dossier()->getPersons());
    }

    #[LiveListener('dossier-persons:changed')]
    public function refreshPersons(): void
    {
        // Re-render only: the budget warning reads the fresh incomes.
        $this->ensureAdmin();
    }

    public function getCurrentGroundFloor(): ?string
    {
        return $this->dossier()->getSearch()?->getGroundFloor();
    }

    /** Optional single-select ("yes" accepted / "no" excluded), toggle-off. */
    #[LiveAction]
    public function chooseGroundFloor(#[LiveArg] string $value): void
    {
        $this->applyToggle(SearchCriterion::GroundFloor, $value);
    }

    public function getCurrentTopFloor(): ?string
    {
        return $this->dossier()->getSearch()?->getTopFloor();
    }

    /** Optional single-select ("yes" accepted / "no" excluded), toggle-off. */
    #[LiveAction]
    public function chooseTopFloor(#[LiveArg] string $value): void
    {
        $this->applyToggle(SearchCriterion::TopFloor, $value);
    }

    public function getCurrentParking(): ?string
    {
        return $this->dossier()->getSearch()?->getParking();
    }

    /** Optional single-select, toggle-off on the active chip. */
    #[LiveAction]
    public function chooseParking(#[LiveArg] string $value): void
    {
        $this->applyToggle(SearchCriterion::Parking, $value);
    }

    #[LiveAction]
    public function save(): void
    {
        $this->ensureAdmin();
        if ($this->isFrozen()) {
            return;
        }

        $autosave = SearchAutosave::fromRaw(
            $this->budget,
            $this->moveInAt,
            $this->minSurface,
            $this->areas,
            $this->propertyType,
        );
        $autosave->apply($this->search());
        $this->em->flush();

        // Props mirror what was actually persisted (normalized values).
        $this->budget = $autosave->budgetProp();
        $this->areas = $autosave->areas;
        $this->moveInAt = $autosave->moveInAtProp();
        $this->propertyType = $autosave->propertyType;
        $this->minSurface = $autosave->minSurfaceProp();

        // Unlocks the next step live when completeness flips.
        $this->commit(alreadyFlushed: true);
        // Clears the leave-guard dirty flag on the front.
        $this->dispatchBrowserEvent('dossier-search:saved');
    }

    #[LiveAction]
    public function saveNote(): void
    {
        $this->ensureAdmin();
        if ($this->isFrozen()) {
            return;
        }

        // Bounded: the writable prop otherwise accepts an arbitrarily large
        // blob, re-serialized into every live payload afterwards.
        $this->note = mb_substr(trim($this->note), 0, 2000);
        $this->search()->setNote('' !== $this->note ? $this->note : null);
        $this->em->flush();

        // Back to read mode once something is saved; an emptied note keeps
        // the textarea open.
        $this->editingNote = '' === $this->note;
        $this->dispatchBrowserEvent('dossier-search:saved');
    }

    #[LiveAction]
    public function startEditingNote(): void
    {
        $this->ensureAdmin();
        $this->editingNote = true;
    }

    /**
     * Guard + toggle shared by every chip action ('' clicks are no-ops,
     * unknown values are rejected by the toggler with a 400).
     */
    private function applyToggle(SearchCriterion $criterion, string|int $value): void
    {
        $this->ensureAdmin();
        if ($this->isFrozen()) {
            return;
        }

        if ('' === $value) {
            return;
        }

        $this->toggler->toggle($this->search(), $criterion, $value);
        $this->commit();
    }

    /** Get-or-create: dossiers created from scratch start without a row. */
    private function search(): DossierSearch
    {
        $dossier = $this->dossier();
        $search = $dossier->getSearch();
        if (null === $search) {
            $search = new DossierSearch();
            $dossier->setSearch($search);
            $this->em->persist($search);
        }

        return $search;
    }

    private function dossier(): Dossier
    {
        return $this->dossiers->find($this->dossierId)
            ?? throw new NotFoundHttpException('Dossier not found.');
    }

    /**
     * Aucune écriture possible tant que le cadenas anti-missclick est mis,
     * ni tant que l'étape Personnes n'est pas validée : la recherche est la
     * deuxième étape du parcours, elle n'ouvre qu'après la première.
     */
    private function isFrozen(): bool
    {
        return $this->locked || !$this->isStepOpen();
    }

    public function isStepOpen(): bool
    {
        return $this->progress->forDossier($this->dossier())->isUnlocked(DossierStep::Search);
    }

    /** État validé de l'étape Recherche, pour le pied de card. */
    public function isStepValidated(): bool
    {
        return $this->progress->forDossier($this->dossier())->isValidated(DossierStep::Search);
    }

    /** "Rouvrir" uniquement tant que l'étape suivante n'est pas validée. */
    public function getCanReopenStep(): bool
    {
        $progress = $this->progress->forDossier($this->dossier());

        return $progress->isValidated(DossierStep::Search) && !$progress->isValidated(DossierStep::File);
    }

    /** Bouton "Valider" en bas de la card : déverrouille l'étape suivante. */
    #[LiveAction]
    public function validateStep(): void
    {
        $this->ensureAdmin();
        // Une étape Recherche ne se valide qu'avec des critères complets :
        // le pied de card masque déjà le bouton, cette garde couvre l'appel
        // direct au endpoint /_components/.
        if (!$this->isComplete()) {
            return;
        }
        if ($this->stepValidator->validate($this->dossier(), DossierStep::Search)) {
            // Enchaînement naturel : l'onglet suivant s'ouvre (l'URL est
            // mise à jour avant le morph déclenché par progress:changed).
            $this->dispatchBrowserEvent('dossier-step:validated', ['next' => DossierStep::File->value]);
            // La barre d'onglets vit dans le chrome de page : un morph Turbo
            // la met à jour (déverrouillage de l'onglet suivant).
            $this->dispatchBrowserEvent('dossier-progress:changed');
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.stepValidated')]);
        }
    }

    #[LiveAction]
    public function reopenStep(): void
    {
        $this->ensureAdmin();
        if ($this->stepValidator->reopen($this->dossier(), DossierStep::Search)) {
            $this->dispatchBrowserEvent('dossier-progress:changed');
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.stepReopened')]);
        }
    }

    /**
     * Commit d'un autosave : persiste, réaligne le statut du dossier sur les
     * étapes désormais validées, puis prévient les autres cards (la barre
     * d'onglets déverrouille l'étape suivante sans rechargement).
     */
    private function commit(bool $alreadyFlushed = false): void
    {
        if (!$alreadyFlushed) {
            $this->em->flush();
        }
        $this->advancer->advance($this->dossier());
        $this->emit('dossier-search:changed');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_DOSSIERS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
