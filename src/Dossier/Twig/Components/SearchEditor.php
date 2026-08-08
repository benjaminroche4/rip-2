<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Contact\Domain\Furnishing;
use App\Contact\Domain\GuarantorType;
use App\Contact\Domain\ParisDistricts;
use App\Contact\Domain\StayDuration;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierSearch;
use App\Dossier\Repository\DossierRepository;
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
 */
#[AsLiveComponent(name: 'Dossier:Search', template: 'components/Dossier/Search.html.twig')]
final class SearchEditor
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $dossierId = 0;

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

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
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
        return array_values(array_filter(explode(',', $this->propertyType)));
    }

    /**
     * One-click chips, multi-select. NOT named setPropertyType(): the
     * PropertyAccessor would call it during hydration of the writable prop.
     */
    #[LiveAction]
    public function togglePropertyType(#[LiveArg] string $type): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $type) {
            return;
        }

        if (null === PropertyType::tryFrom($type)) {
            throw new BadRequestHttpException(\sprintf('Unknown property type "%s".', $type));
        }

        $selected = $this->getSelectedPropertyTypes();
        $selected = \in_array($type, $selected, true)
            ? array_values(array_diff($selected, [$type]))
            : [...$selected, $type];

        $this->propertyType = implode(',', $selected);
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
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $duration) {
            return;
        }

        $case = StayDuration::tryFrom($duration)
            ?? throw new BadRequestHttpException(\sprintf('Unknown stay duration "%s".', $duration));

        $search = $this->search();
        $search->setStayDuration($search->getStayDuration() === $case->value ? null : $case->value);
        $this->em->flush();

        $this->emit('dossier-search:changed');
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
        return array_values(array_filter(explode(',', (string) $this->dossier()->getSearch()?->getFurnishing())));
    }

    /** Multi-select: a tenant can be open to both. */
    #[LiveAction]
    public function chooseFurnishing(#[LiveArg] string $furnishing): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $furnishing) {
            return;
        }

        if (null === Furnishing::tryFrom($furnishing)) {
            throw new BadRequestHttpException(\sprintf('Unknown furnishing "%s".', $furnishing));
        }

        $selected = $this->getSelectedFurnishings();
        $selected = \in_array($furnishing, $selected, true)
            ? array_values(array_diff($selected, [$furnishing]))
            : [...$selected, $furnishing];

        $this->search()->setFurnishing(implode(',', $selected) ?: null);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    /**
     * @return list<GuarantorType>
     */
    public function getGuarantorTypes(): array
    {
        return GuarantorType::cases();
    }

    public function getCurrentGuarantorType(): ?string
    {
        return $this->dossier()->getSearch()?->getGuarantorType();
    }

    #[LiveAction]
    public function chooseGuarantorType(#[LiveArg] string $guarantor): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $guarantor) {
            return;
        }

        $case = GuarantorType::tryFrom($guarantor)
            ?? throw new BadRequestHttpException(\sprintf('Unknown guarantor type "%s".', $guarantor));

        $search = $this->search();
        $search->setGuarantorType($search->getGuarantorType() === $case->value ? null : $case->value);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    /** Progress of the guarantee, clear labels in translations. */
    public const GUARANTOR_STATUSES = ['not_started', 'in_progress', 'obtained', 'refused'];

    /**
     * @return list<string>
     */
    public function getGuarantorStatuses(): array
    {
        return self::GUARANTOR_STATUSES;
    }

    public function getCurrentGuarantorStatus(): ?string
    {
        return $this->dossier()->getSearch()?->getGuarantorStatus();
    }

    #[LiveAction]
    public function chooseGuarantorStatus(#[LiveArg] string $status): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $status) {
            return;
        }

        if (!\in_array($status, self::GUARANTOR_STATUSES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown guarantor status "%s".', $status));
        }

        $search = $this->search();
        $search->setGuarantorStatus($search->getGuarantorStatus() === $status ? null : $status);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    public function getCurrentOccupants(): ?int
    {
        return $this->dossier()->getSearch()?->getOccupants();
    }

    #[LiveAction]
    public function chooseOccupants(#[LiveArg] int $count): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ($count < 1 || $count > 6) {
            throw new BadRequestHttpException(\sprintf('Invalid occupants count "%d".', $count));
        }

        $search = $this->search();
        $search->setOccupants($search->getOccupants() === $count ? null : $count);
        $this->em->flush();

        $this->emit('dossier-search:changed');
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
        return array_values(array_filter(explode(',', (string) $this->dossier()->getSearch()?->getEquipment())));
    }

    #[LiveAction]
    public function chooseEquipment(#[LiveArg] string $equipment): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $equipment) {
            return;
        }

        if (null === Amenity::tryFrom($equipment)) {
            throw new BadRequestHttpException(\sprintf('Unknown equipment "%s".', $equipment));
        }

        $selected = $this->getSelectedEquipment();
        $selected = \in_array($equipment, $selected, true)
            ? array_values(array_diff($selected, [$equipment]))
            : [...$selected, $equipment];

        $this->search()->setEquipment(implode(',', $selected) ?: null);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    /** Allowed values of the optional yes/no criteria (pets, early move-in). */
    private const YES_NO = ['yes', 'no'];

    /** Household typologies of the project, clear labels in translations. */
    private const HOUSEHOLD_TYPES = ['alone', 'couple', 'family', 'flatshare', 'other'];

    /** Desired lease types (French rental market), multi-select. */
    private const LEASE_TYPES = ['civil_code', 'alur', 'mobility'];

    /**
     * @return list<string>
     */
    public function getLeaseTypes(): array
    {
        return self::LEASE_TYPES;
    }

    /**
     * @return list<string>
     */
    public function getSelectedLeaseTypes(): array
    {
        return array_values(array_filter(explode(',', (string) $this->dossier()->getSearch()?->getLeaseTypes())));
    }

    /** Multi-select chips: a tenant can be open to several lease types. */
    #[LiveAction]
    public function chooseLeaseType(#[LiveArg] string $lease): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $lease) {
            return;
        }

        if (!\in_array($lease, self::LEASE_TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown lease type "%s".', $lease));
        }

        $selected = $this->getSelectedLeaseTypes();
        $selected = \in_array($lease, $selected, true)
            ? array_values(array_diff($selected, [$lease]))
            : [...$selected, $lease];

        $this->search()->setLeaseTypes(implode(',', $selected) ?: null);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    /**
     * Lease type vs stay duration guard: a mobility lease is capped at 10
     * months, an ALUR lease starts at 1 year. Returns the translation key
     * of the mismatch to surface, or null when consistent.
     */
    public function getLeaseMismatch(): ?string
    {
        $search = $this->dossier()->getSearch();
        if (null === $search) {
            return null;
        }

        $leases = explode(',', (string) $search->getLeaseTypes());
        $duration = $search->getStayDuration();

        if (\in_array('mobility', $leases, true) && 'long' === $duration) {
            return 'admin.dossiers.show.search.leaseMismatch.mobilityLong';
        }
        if (\in_array('alur', $leases, true) && 'short' === $duration) {
            return 'admin.dossiers.show.search.leaseMismatch.alurShort';
        }

        return null;
    }

    /** Common destinations for the "important addresses" rows. */
    private const IMPORTANT_ADDRESS_TYPES = ['work', 'school', 'daycare', 'family', 'gym', 'other'];
    public const MAX_IMPORTANT_ADDRESSES = 3;

    #[LiveAction]
    public function toggleLock(): void
    {
        $this->ensureAdmin();
        $this->locked = !$this->locked;
    }

    /**
     * @return list<string>
     */
    public function getImportantAddressTypes(): array
    {
        return self::IMPORTANT_ADDRESS_TYPES;
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

        if (!\in_array($type, self::IMPORTANT_ADDRESS_TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown address type "%s".', $type));
        }

        $this->addressTypeDraft = $type;
    }

    #[LiveAction]
    public function addImportantAddress(): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        $address = mb_substr(trim($this->addressDraft), 0, 255);
        if ('' === $address) {
            return;
        }
        if (!\in_array($this->addressTypeDraft, self::IMPORTANT_ADDRESS_TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown address type "%s".', $this->addressTypeDraft));
        }

        $rows = $this->getImportantAddresses();
        if (\count($rows) >= self::MAX_IMPORTANT_ADDRESSES) {
            return;
        }

        $row = ['address' => $address, 'type' => $this->addressTypeDraft];
        $lat = filter_var(trim($this->addressLatDraft), \FILTER_VALIDATE_FLOAT);
        $lng = filter_var(trim($this->addressLngDraft), \FILTER_VALIDATE_FLOAT);
        if (false !== $lat && false !== $lng && abs($lat) <= 90.0 && abs($lng) <= 180.0) {
            $row['lat'] = round($lat, 6);
            $row['lng'] = round($lng, 6);
        }

        $rows[] = $row;
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
        if ($this->locked) {
            return;
        }

        $rows = $this->getImportantAddresses();
        if (!isset($rows[$index])) {
            return;
        }

        unset($rows[$index]);
        $this->search()->setImportantAddresses(array_values($rows));
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
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $value) {
            return;
        }

        if (!\in_array($value, self::YES_NO, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown pets value "%s".', $value));
        }

        $search = $this->search();
        $search->setPets($search->getPets() === $value ? null : $value);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    /**
     * @return list<string>
     */
    public function getHouseholdTypes(): array
    {
        return self::HOUSEHOLD_TYPES;
    }

    /** Optional custom dropdown: choosing persists immediately ('' = none). */
    #[LiveAction]
    public function chooseHouseholdType(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' !== $value && !\in_array($value, self::HOUSEHOLD_TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown household type "%s".', $value));
        }

        $this->householdType = $value;
        $this->search()->setHouseholdType('' !== $value ? $value : null);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    public function getCurrentEarlyMoveIn(): ?string
    {
        return $this->dossier()->getSearch()?->getEarlyMoveIn();
    }

    /** Optional single-select: willing to take the flat before the date. */
    #[LiveAction]
    public function chooseEarlyMoveIn(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $value) {
            return;
        }

        if (!\in_array($value, self::YES_NO, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown early move-in value "%s".', $value));
        }

        $search = $this->search();
        $search->setEarlyMoveIn($search->getEarlyMoveIn() === $value ? null : $value);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    /** Chips 1..4 ("4+"), single-select with toggle-off. */
    public function getCurrentMinBedrooms(): ?int
    {
        return $this->dossier()->getSearch()?->getMinBedrooms();
    }

    #[LiveAction]
    public function chooseMinBedrooms(#[LiveArg] int $count): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ($count < 1 || $count > 4) {
            throw new BadRequestHttpException(\sprintf('Invalid bedroom count "%d".', $count));
        }

        $search = $this->search();
        $search->setMinBedrooms($search->getMinBedrooms() === $count ? null : $count);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    public function getCurrentElevator(): ?string
    {
        return $this->dossier()->getSearch()?->getElevator();
    }

    /** Optional single-select, toggle-off on the active chip. */
    #[LiveAction]
    public function chooseElevator(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $value) {
            return;
        }

        if (!\in_array($value, self::YES_NO, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown elevator value "%s".', $value));
        }

        $search = $this->search();
        $search->setElevator($search->getElevator() === $value ? null : $value);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    /**
     * Highest advisable monthly rent given the tenants' net incomes (the
     * landlord's "3x rule"). Null while no tenant income is known.
     */
    public function getMaxAffordableBudget(): ?int
    {
        $income = 0;
        foreach ($this->dossier()->getPersons() as $person) {
            if (DossierPersonRole::TENANT === $person->getRole()) {
                $income += $person->getMonthlyIncome() ?? 0;
            }
        }

        return $income > 0 ? intdiv($income, 3) : null;
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
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $value) {
            return;
        }

        if (!\in_array($value, self::YES_NO, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown ground floor value "%s".', $value));
        }

        $search = $this->search();
        $search->setGroundFloor($search->getGroundFloor() === $value ? null : $value);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    public function getCurrentTopFloor(): ?string
    {
        return $this->dossier()->getSearch()?->getTopFloor();
    }

    /** Optional single-select ("yes" accepted / "no" excluded), toggle-off. */
    #[LiveAction]
    public function chooseTopFloor(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $value) {
            return;
        }

        if (!\in_array($value, self::YES_NO, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown top floor value "%s".', $value));
        }

        $search = $this->search();
        $search->setTopFloor($search->getTopFloor() === $value ? null : $value);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    public function getCurrentParking(): ?string
    {
        return $this->dossier()->getSearch()?->getParking();
    }

    /** Optional single-select, toggle-off on the active chip. */
    #[LiveAction]
    public function chooseParking(#[LiveArg] string $value): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        if ('' === $value) {
            return;
        }

        if (!\in_array($value, self::YES_NO, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown parking value "%s".', $value));
        }

        $search = $this->search();
        $search->setParking($search->getParking() === $value ? null : $value);
        $this->em->flush();

        $this->emit('dossier-search:changed');
    }

    #[LiveAction]
    public function save(): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        $budget = '' !== trim($this->budget) && is_numeric(trim($this->budget))
            ? max(0, (int) trim($this->budget))
            : null;
        $moveInAt = '' !== trim($this->moveInAt)
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', trim($this->moveInAt)) ?: null)
            : null;
        if (null !== $moveInAt && $moveInAt < new \DateTimeImmutable('today')) {
            // A desired move-in date can only be today or later.
            $moveInAt = null;
        }

        $minSurface = '' !== trim($this->minSurface) && is_numeric(trim($this->minSurface))
            ? max(0, (int) trim($this->minSurface))
            : null;

        $search = $this->search();
        $search->setBudget($budget);
        $search->setAreas('' !== trim($this->areas) ? trim($this->areas) : null);
        $search->setMoveInAt($moveInAt);
        $search->setPropertyType('' !== trim($this->propertyType) ? trim($this->propertyType) : null);
        $search->setMinSurface($minSurface);
        $this->em->flush();

        $this->budget = null !== $budget ? (string) $budget : '';
        $this->areas = trim($this->areas);
        $this->moveInAt = $moveInAt?->format('Y-m-d') ?? '';
        $this->propertyType = trim($this->propertyType);
        $this->minSurface = null !== $minSurface ? (string) $minSurface : '';

        // Unlocks the modules card live when completeness flips.
        $this->emit('dossier-search:changed');
        // Clears the leave-guard dirty flag on the front.
        $this->dispatchBrowserEvent('dossier-search:saved');
    }

    #[LiveAction]
    public function saveNote(): void
    {
        $this->ensureAdmin();
        if ($this->locked) {
            return;
        }

        $this->note = trim($this->note);
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

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
