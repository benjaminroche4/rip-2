<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Contact\Domain\ContactListItem;
use App\Contact\Repository\ContactRepository;
use App\PropertyListing\Domain\PropertyType;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Point;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Structured housing project on the contact detail page (budget, target
 * areas, move-in date, property type), filled by the admin from the call.
 * Everything the message hides in free text, made comparable.
 */
#[AsLiveComponent(name: 'Admin:ContactProject', template: 'components/Admin/ContactProject.html.twig')]
final class ContactProject
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $contactId = 0;

    #[LiveProp(writable: true)]
    public string $budget = '';

    #[LiveProp(writable: true)]
    public string $areas = '';

    /** "Y-m-d" (date input format), '' = none. */
    #[LiveProp(writable: true)]
    public string $moveInAt = '';

    #[LiveProp(writable: true)]
    public string $propertyType = '';

    /** Free-form project note, same read-mode/pencil flow as LeadQuality. */
    #[LiveProp(writable: true)]
    public string $projectNote = '';

    #[LiveProp]
    public bool $editingProjectNote = false;

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(int $contactId): void
    {
        $this->ensureAdmin();
        $this->contactId = $contactId;

        $contact = $this->getContact();
        $this->budget = null !== $contact?->projectBudget ? (string) $contact->projectBudget : '';
        $this->areas = $contact?->projectAreas ?? '';
        $this->moveInAt = $contact?->projectMoveInAt?->format('Y-m-d') ?? '';
        $this->propertyType = $contact?->projectPropertyType ?? '';
        $this->projectNote = $contact?->projectNote ?? '';
        $this->editingProjectNote = '' === $this->projectNote;
    }

    #[LiveAction]
    public function saveProjectNote(): void
    {
        $this->ensureAdmin();

        $this->repository->saveProjectNote($this->contactId, $this->projectNote);
        $this->projectNote = trim($this->projectNote);
        // Back to read mode once something is saved; an emptied note keeps
        // the textarea open.
        $this->editingProjectNote = '' === $this->projectNote;
        $this->dispatchBrowserEvent('contact-project:saved');
    }

    #[LiveAction]
    public function startEditingProjectNote(): void
    {
        $this->ensureAdmin();
        $this->editingProjectNote = true;
    }

    public function getContact(): ?ContactListItem
    {
        return $this->repository->listByIds([$this->contactId])[0] ?? null;
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
            static fn (string $code): array => ['code' => $code, 'label' => \App\Contact\Domain\ParisDistricts::LABELS[$code] ?? $code],
            array_values(array_filter(array_map(trim(...), explode(',', $this->areas)))),
        );
    }

    /**
     * Same catalogue as the public "list my property" form.
     *
     * @return list<PropertyType>
     */
    public function getPropertyTypes(): array
    {
        return PropertyType::cases();
    }

    /**
     * Selected types as enum values ("t2,loft" in storage).
     *
     * @return list<string>
     */
    public function getSelectedPropertyTypes(): array
    {
        return array_values(array_filter(explode(',', $this->propertyType)));
    }

    /**
     * One-click chips, multi-select: toggles the type and persists
     * immediately. NOT named setPropertyType(): PropertyAccessor would
     * treat it as the setter of the writable "propertyType" prop and call
     * it during hydration, toggling the selection on every request.
     */
    #[LiveAction]
    public function togglePropertyType(#[LiveArg] string $type): void
    {
        $this->ensureAdmin();

        // A stale DOM mid-morph can fire with an empty arg: ignore, never 500.
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
     * @return list<\App\Contact\Domain\StayDuration>
     */
    public function getStayDurations(): array
    {
        return \App\Contact\Domain\StayDuration::cases();
    }

    /** One-click chips, single-select with toggle-off on the active one. */
    #[LiveAction]
    public function chooseStayDuration(#[LiveArg] string $duration): void
    {
        $this->ensureAdmin();

        if ('' === $duration) {
            return;
        }

        $case = \App\Contact\Domain\StayDuration::tryFrom($duration)
            ?? throw new BadRequestHttpException(\sprintf('Unknown stay duration "%s".', $duration));

        $current = $this->getContact()?->projectStayDuration;
        $this->repository->saveStayDuration($this->contactId, $current === $case ? null : $case);
    }

    /**
     * @return list<\App\Contact\Domain\Furnishing>
     */
    public function getFurnishings(): array
    {
        return \App\Contact\Domain\Furnishing::cases();
    }

    #[LiveAction]
    public function chooseFurnishing(#[LiveArg] string $furnishing): void
    {
        $this->ensureAdmin();

        if ('' === $furnishing) {
            return;
        }

        $case = \App\Contact\Domain\Furnishing::tryFrom($furnishing)
            ?? throw new BadRequestHttpException(\sprintf('Unknown furnishing "%s".', $furnishing));

        $current = $this->getContact()?->projectFurnishing;
        $this->repository->saveFurnishing($this->contactId, $current === $case ? null : $case);
    }

    /**
     * @return list<\App\Contact\Domain\GuarantorType>
     */
    public function getGuarantorTypes(): array
    {
        return \App\Contact\Domain\GuarantorType::cases();
    }

    #[LiveAction]
    public function chooseGuarantorType(#[LiveArg] string $guarantor): void
    {
        $this->ensureAdmin();

        if ('' === $guarantor) {
            return;
        }

        $case = \App\Contact\Domain\GuarantorType::tryFrom($guarantor)
            ?? throw new BadRequestHttpException(\sprintf('Unknown guarantor type "%s".', $guarantor));

        $current = $this->getContact()?->projectGuarantorType;
        $this->repository->saveGuarantorType($this->contactId, $current === $case ? null : $case);
    }

    #[LiveAction]
    public function save(): void
    {
        $this->ensureAdmin();

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

        $this->repository->saveProject($this->contactId, $budget, $this->areas, $moveInAt, $this->propertyType);

        $this->budget = null !== $budget ? (string) $budget : '';
        $this->areas = trim($this->areas);
        $this->moveInAt = $moveInAt?->format('Y-m-d') ?? '';
        $this->propertyType = trim($this->propertyType);

        // Clears the leave-guard dirty flag on the front.
        $this->dispatchBrowserEvent('contact-project:saved');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
