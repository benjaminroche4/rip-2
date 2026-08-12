<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\DossierPersonView;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\DossierEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Persons section of the dossier detail page: inline edit of each person,
 * add (up to 4, max 2 per role), remove, and the movable primary-tenant tag.
 * Business rules enforced on every mutation: at least one person, always at
 * least one tenant, exactly one primary among tenants. The dossier name is
 * regenerated from the tenants after each change (it is derived, not typed).
 */
#[AsLiveComponent(name: 'Dossier:PersonsManager', template: 'components/Dossier/PersonsManager.html.twig')]
final class PersonsManager
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $dossierId = 0;

    /** Fold state of the card, kept server-side so a morph cannot undo it. */
    #[LiveProp]
    public bool $expanded = true;

    /** Person id currently in edit mode, or null. */
    #[LiveProp]
    public ?int $editId = null;

    /** True while the "new person" card is open. */
    #[LiveProp]
    public bool $adding = false;

    /** Person id awaiting removal confirmation in the modal, or null. */
    #[LiveProp]
    public ?int $confirmRemoveId = null;

    /** Validation errors keyed by field ('global' for card-level rules). */
    #[LiveProp]
    public array $errors = [];

    #[LiveProp(writable: true)]
    public string $role = '';

    #[LiveProp(writable: true)]
    public string $firstName = '';

    #[LiveProp(writable: true)]
    public string $lastName = '';

    #[LiveProp(writable: true)]
    public string $email = '';

    #[LiveProp(writable: true)]
    public string $phone = '';

    #[LiveProp(writable: true)]
    public string $language = ContactLanguage::FR->value;

    /** Professional situation ('' = unspecified), see PROFESSIONS. */
    #[LiveProp(writable: true)]
    public string $profession = '';

    /** "No professional activity": hides and clears the whole pro pane. */
    #[LiveProp(writable: true)]
    public bool $noProfession = false;

    #[LiveProp(writable: true)]
    public string $employer = '';

    #[LiveProp(writable: true)]
    public string $jobTitle = '';

    /** Contract start date, "Y-m-d", '' = unspecified. */
    #[LiveProp(writable: true)]
    public string $contractStart = '';

    /** Contract end date (CDD only), "Y-m-d", '' = unspecified. */
    #[LiveProp(writable: true)]
    public string $contractEnd = '';

    /** CDI only: trial period over ("essai validé"). */
    #[LiveProp(writable: true)]
    public bool $trialOver = false;

    /** Net monthly income in €, '' = unspecified. */
    #[LiveProp(writable: true)]
    public string $monthlyIncome = '';

    /** Birth date, "Y-m-d" (date input format), '' = unspecified. */
    #[LiveProp(writable: true)]
    public string $birthDate = '';

    /** ISO 3166-1 alpha-2 nationality, '' = unspecified. */
    #[LiveProp(writable: true)]
    public string $nationality = '';

    /** City where the person currently lives, '' = unspecified. */
    #[LiveProp(writable: true)]
    public string $currentCity = '';

    /** Visa need (yes, no, obtained), '' = unspecified. */
    #[LiveProp(writable: true)]
    public string $visaStatus = '';

    /** Professional situations offered as chips, clear labels in translations. */
    public const PROFESSIONS = ['cdi', 'cdd', 'student', 'business_owner', 'freelance', 'liberal', 'company', 'retired'];

    /**
     * Situations no longer offered but still stored on older persons: they
     * stay valid on save (and their chip shows) so those cards remain
     * editable without silently rewriting history.
     */
    public const LEGACY_PROFESSIONS = ['expat', 'other'];

    /**
     * Which pro fields exist for which situation (shared with the template
     * and the pro-fields Stimulus controller): anything not listed for the
     * saved situation is cleared, never silently kept. Self-employed
     * situations reuse contractStart as the activity start date; legacy
     * situations keep their historic field set.
     */
    public const PRO_FIELD_PROFESSIONS = [
        'employer' => ['cdi', 'cdd', 'expat'],
        'jobTitle' => ['cdi', 'cdd', 'business_owner', 'freelance', 'liberal', 'company', 'expat', 'other'],
        'contractStart' => ['cdi', 'cdd', 'business_owner', 'freelance', 'liberal', 'company', 'expat'],
        'contractEnd' => ['cdd'],
        'trialOver' => ['cdi'],
    ];

    /** Visa need options ('' = unspecified). */
    public const VISA_STATUSES = ['yes', 'no', 'obtained'];

    public function __construct(
        private readonly DossierRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly DossierEventLogger $events,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    /**
     * @return list<DossierPersonView>
     */
    public function getPersons(): array
    {
        $views = [];
        foreach ($this->dossier()->getPersons() as $person) {
            $views[] = new DossierPersonView(
                id: (int) $person->getId(),
                role: $person->getRole() ?? DossierPersonRole::TENANT,
                firstName: (string) $person->getFirstName(),
                lastName: (string) $person->getLastName(),
                email: (string) $person->getEmail(),
                phone: $person->getPhone(),
                language: $person->getLanguage() ?? ContactLanguage::FR,
                primaryContact: $person->isPrimaryContact(),
                profession: $person->getProfession(),
                monthlyIncome: $person->getMonthlyIncome(),
                birthDate: $person->getBirthDate(),
                nationality: $person->getNationality(),
                noProfession: $person->isNoProfession(),
                employer: $person->getEmployer(),
                jobTitle: $person->getJobTitle(),
                contractStartDate: $person->getContractStartDate(),
                contractEndDate: $person->getContractEndDate(),
                trialPeriodOver: $person->isTrialPeriodOver(),
                currentCity: $person->getCurrentCity(),
                visaStatus: $person->getVisaStatus(),
            );
        }

        return $views;
    }

    public function getCanAdd(): bool
    {
        return $this->dossier()->getPersons()->count() < Dossier::MAX_PERSONS;
    }

    /** Fires alongside the native <details> toggle, to keep the state. */
    #[LiveAction]
    public function toggleCard(): void
    {
        $this->ensureAdmin();
        $this->expanded = !$this->expanded;
    }

    #[LiveAction]
    public function startAdd(): void
    {
        $this->ensureAdmin();
        if (!$this->getCanAdd()) {
            return;
        }
        $this->resetDraft();
        $this->adding = true;
        $this->editId = null;
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $person = $this->person($key);

        $this->resetDraft();
        $this->editId = (int) $person->getId();
        $this->adding = false;
        $this->role = $person->getRole()?->value ?? '';
        $this->firstName = (string) $person->getFirstName();
        $this->lastName = (string) $person->getLastName();
        $this->email = (string) $person->getEmail();
        $this->phone = (string) $person->getPhone();
        $this->language = $person->getLanguage()?->value ?? ContactLanguage::FR->value;
        $this->profession = $person->getProfession() ?? '';
        $this->noProfession = $person->isNoProfession();
        $this->employer = (string) $person->getEmployer();
        $this->jobTitle = (string) $person->getJobTitle();
        $this->contractStart = $person->getContractStartDate()?->format('Y-m-d') ?? '';
        $this->contractEnd = $person->getContractEndDate()?->format('Y-m-d') ?? '';
        $this->trialOver = $person->isTrialPeriodOver();
        $this->monthlyIncome = null !== $person->getMonthlyIncome() ? (string) $person->getMonthlyIncome() : '';
        $this->birthDate = $person->getBirthDate()?->format('Y-m-d') ?? '';
        $this->nationality = $person->getNationality() ?? '';
        $this->currentCity = $person->getCurrentCity() ?? '';
        $this->visaStatus = $person->getVisaStatus() ?? '';
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->ensureAdmin();
        $this->resetDraft();
    }

    #[LiveAction]
    public function savePerson(): void
    {
        $this->ensureAdmin();
        $dossier = $this->dossier();

        $role = DossierPersonRole::tryFrom($this->role);
        $language = ContactLanguage::tryFrom($this->language);
        $firstName = trim($this->firstName);
        $lastName = trim($this->lastName);
        $email = trim($this->email);
        $phone = trim($this->phone);

        $this->errors = [];
        if (null === $role) {
            $this->errors['role'] = 'admin.dossiers.create.person.role.notNull';
        }
        if (mb_strlen($firstName) < 2 || mb_strlen($firstName) > 50) {
            $this->errors['firstName'] = '' === $firstName ? 'admin.dossiers.create.person.firstName.notBlank' : 'admin.dossiers.create.person.firstName.length';
        }
        if (mb_strlen($lastName) < 2 || mb_strlen($lastName) > 50) {
            $this->errors['lastName'] = '' === $lastName ? 'admin.dossiers.create.person.lastName.notBlank' : 'admin.dossiers.create.person.lastName.length';
        }
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
            $this->errors['email'] = '' === $email ? 'admin.dossiers.create.person.email.notBlank' : 'admin.dossiers.create.person.email.invalid';
        }
        if ('' !== $phone && (mb_strlen($phone) > 30 || 1 !== preg_match('/^\+?\d[\d .\-]*$/', $phone))) {
            $this->errors['phone'] = 'admin.dossiers.create.person.phone.invalid';
        }
        if (null === $language) {
            $this->errors['language'] = 'admin.dossiers.create.person.language.label';
        }
        $profession = trim($this->profession);
        if ('' !== $profession && !\in_array($profession, [...self::PROFESSIONS, ...self::LEGACY_PROFESSIONS], true)) {
            $this->errors['profession'] = 'admin.dossiers.create.person.profession.invalid';
        }
        // A follow-up contact is identity + contact details only (mirrors
        // the client-side visibility). The tenant-specific fields are
        // cleared for new persons and on a real tenant → follow-up
        // downgrade, never on a plain edit of an already-non-tenant person:
        // legacy cards may hold a birth date or nationality that a simple
        // email fix must not wipe.
        $previousRole = !$this->adding && null !== $this->editId
            ? $this->person((int) $this->editId)->getRole()
            : null;
        if (DossierPersonRole::TENANT !== $role
            && ($this->adding || DossierPersonRole::TENANT === $previousRole)) {
            $this->noProfession = false;
            $this->profession = '';
            $this->monthlyIncome = '';
            $profession = '';
            $this->birthDate = '';
            $this->nationality = '';
            $this->currentCity = '';
            $this->visaStatus = '';
        }
        // "No professional activity" empties the whole pane; otherwise a
        // field only survives if it exists for the saved situation.
        if ($this->noProfession) {
            $profession = '';
        }
        $keeps = static fn (string $field): bool => \in_array($profession, self::PRO_FIELD_PROFESSIONS[$field], true);
        $employer = $keeps('employer') ? mb_substr(trim($this->employer), 0, 100) : '';
        $jobTitle = $keeps('jobTitle') ? mb_substr(trim($this->jobTitle), 0, 100) : '';
        $trialOver = $keeps('trialOver') && $this->trialOver;
        $contractStart = null;
        if ($keeps('contractStart') && '' !== trim($this->contractStart)) {
            $contractStart = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($this->contractStart)) ?: null;
            if (null === $contractStart || $contractStart < new \DateTimeImmutable('1950-01-01')) {
                $this->errors['contractStart'] = 'admin.dossiers.show.persons.pro.contractStart.invalid';
            }
        }
        $contractEnd = null;
        if ($keeps('contractEnd') && '' !== trim($this->contractEnd)) {
            $contractEnd = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($this->contractEnd)) ?: null;
            if (null === $contractEnd || (null !== $contractStart && $contractEnd < $contractStart)) {
                $this->errors['contractEnd'] = 'admin.dossiers.show.persons.pro.contractEnd.invalid';
            }
        }
        $monthlyIncome = $this->noProfession ? '' : trim($this->monthlyIncome);
        if ('' !== $monthlyIncome && (!is_numeric($monthlyIncome) || (int) $monthlyIncome < 0)) {
            $this->errors['monthlyIncome'] = 'admin.dossiers.create.person.income.invalid';
        }
        $birthDate = null;
        if ('' !== trim($this->birthDate)) {
            $birthDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($this->birthDate)) ?: null;
            if (null === $birthDate
                || $birthDate > new \DateTimeImmutable('today')
                || $birthDate < new \DateTimeImmutable('1900-01-01')) {
                $this->errors['birthDate'] = 'admin.dossiers.create.person.birthDate.invalid';
            }
        }
        $nationality = strtoupper(trim($this->nationality));
        if ('' !== $nationality && !Countries::exists($nationality)) {
            $this->errors['nationality'] = 'admin.dossiers.create.person.nationality.invalid';
        }
        $currentCity = mb_substr(trim($this->currentCity), 0, 100);
        // Stale DOM can post anything: an unknown visa value is dropped.
        $visaStatus = \in_array($this->visaStatus, self::VISA_STATUSES, true) ? $this->visaStatus : '';

        // Household composition once this save lands.
        if (null !== $role) {
            $tenants = 0;
            $followUps = 0;
            foreach ($dossier->getPersons() as $person) {
                if ($person->getId() === $this->editId) {
                    continue;
                }
                DossierPersonRole::TENANT === $person->getRole() ? ++$tenants : ++$followUps;
            }
            DossierPersonRole::TENANT === $role ? ++$tenants : ++$followUps;

            if (0 === $tenants) {
                $this->errors['role'] = 'admin.dossiers.create.persons.tenantRequired';
            } elseif ($tenants > Dossier::MAX_PER_ROLE) {
                $this->errors['role'] = 'admin.dossiers.create.persons.maxTenants';
            } elseif ($followUps > Dossier::MAX_PER_ROLE) {
                $this->errors['role'] = 'admin.dossiers.create.persons.maxFollowUps';
            }
        }

        if ([] !== $this->errors) {
            return;
        }

        if ($this->adding) {
            if (!$this->getCanAdd()) {
                return;
            }
            $person = new DossierPerson();
            $dossier->addPerson($person);
        } else {
            $person = $this->person((int) $this->editId);
        }

        $person->setRole($role);
        $person->setFirstName($firstName);
        $person->setLastName($lastName);
        $person->setEmail($email);
        $person->setPhone('' !== $phone ? $phone : null);
        $person->setLanguage($language);
        $person->setProfession('' !== $profession ? $profession : null);
        $person->setNoProfession($this->noProfession);
        $person->setEmployer('' !== $employer ? $employer : null);
        $person->setJobTitle('' !== $jobTitle ? $jobTitle : null);
        $person->setContractStartDate($contractStart);
        $person->setContractEndDate($contractEnd);
        $person->setTrialPeriodOver($trialOver);
        $person->setMonthlyIncome('' !== $monthlyIncome ? max(0, (int) $monthlyIncome) : null);
        $person->setBirthDate($birthDate);
        $person->setNationality('' !== $nationality ? $nationality : null);
        $person->setCurrentCity('' !== $currentCity ? $currentCity : null);
        $person->setVisaStatus('' !== $visaStatus ? $visaStatus : null);

        $this->normalize($dossier);
        // Every field autosaves through savePerson: only log when something
        // actually changed, or the thread drowns in identical entries.
        $changed = $this->adding;
        if (!$changed) {
            $this->em->getUnitOfWork()->computeChangeSets();
            $changed = [] !== $this->em->getUnitOfWork()->getEntityChangeSet($person);
        }
        if ($changed) {
            $this->events->log($dossier, $this->adding ? 'person_added' : 'person_updated', [
                'name' => trim($firstName.' '.$lastName),
                'role' => 'admin.dossiers.create.person.role.'.$role->value,
            ]);
        }
        $this->em->flush();
        // The search card recomputes its budget warning from the incomes.
        $this->emit('dossier-persons:changed');
        // A new person changes the count badge of the Personnes tab, which
        // lives in the page chrome: a Turbo morph refresh picks it up.
        if ($this->adding) {
            $this->dispatchBrowserEvent('dossier-persons:changed');
        }

        // Explicit validate button (same flow as the lead identity card):
        // a successful save closes the inline form, add and edit alike.
        $this->resetDraft();
    }

    /** Opens the removal confirmation modal for a person. */
    #[LiveAction]
    public function askRemove(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $this->confirmRemoveId = $this->person($key)->getId();
    }

    #[LiveAction]
    public function cancelRemove(): void
    {
        $this->ensureAdmin();
        $this->confirmRemoveId = null;
    }

    #[LiveAction]
    public function removePerson(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $dossier = $this->dossier();
        $person = $this->person($key);

        $this->confirmRemoveId = null;
        $this->errors = [];
        if ($dossier->getPersons()->count() <= Dossier::MIN_PERSONS) {
            $this->errors['global'] = 'admin.dossiers.create.persons.min';

            return;
        }
        if ($person->isPrimaryContact()) {
            // The primary tenant can only leave after the star moved to
            // another tenant; the template hides the button, this guards
            // hand-crafted requests.
            $this->errors['global'] = 'admin.dossiers.show.persons.primaryLocked';

            return;
        }
        $remainingTenants = 0;
        foreach ($dossier->getPersons() as $other) {
            if ($other !== $person && DossierPersonRole::TENANT === $other->getRole()) {
                ++$remainingTenants;
            }
        }
        if (0 === $remainingTenants) {
            $this->errors['global'] = 'admin.dossiers.create.persons.tenantRequired';

            return;
        }

        $removedName = trim(trim((string) $person->getFirstName()).' '.trim((string) $person->getLastName()));
        $dossier->removePerson($person);
        $this->normalize($dossier);
        $this->events->log($dossier, 'person_removed', ['name' => $removedName]);
        $this->em->flush();
        $this->emit('dossier-persons:changed');
        // Keep the Personnes tab count badge in sync (page-chrome morph).
        $this->dispatchBrowserEvent('dossier-persons:changed');
        $this->resetDraft();
    }

    #[LiveAction]
    public function makePrimary(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();
        $dossier = $this->dossier();
        $person = $this->person($key);

        if (DossierPersonRole::TENANT !== $person->getRole()) {
            return;
        }

        foreach ($dossier->getPersons() as $other) {
            $other->setPrimaryContact($other === $person);
        }
        $this->normalize($dossier);
        $this->events->log($dossier, 'primary_changed', [
            'name' => trim(trim((string) $person->getFirstName()).' '.trim((string) $person->getLastName())),
        ]);
        $this->em->flush();
    }

    /**
     * Post-mutation invariants: sequential positions, exactly one primary
     * among tenants (first tenant if the previous primary left), and the
     * derived dossier name (primary tenant first).
     */
    private function normalize(Dossier $dossier): void
    {
        $position = 0;
        foreach ($dossier->getPersons() as $person) {
            $person->setPosition($position++);
        }

        $tenants = [];
        foreach ($dossier->getPersons() as $person) {
            if (DossierPersonRole::TENANT === $person->getRole()) {
                $tenants[] = $person;
            } else {
                $person->setPrimaryContact(false);
            }
        }
        if ([] !== $tenants) {
            $primary = null;
            foreach ($tenants as $tenant) {
                if ($tenant->isPrimaryContact()) {
                    $primary ??= $tenant;
                }
            }
            $primary ??= $tenants[0];
            foreach ($tenants as $tenant) {
                $tenant->setPrimaryContact($tenant === $primary);
            }

            $names = [trim((string) $primary->getLastName())];
            foreach ($tenants as $tenant) {
                if ($tenant !== $primary && '' !== trim((string) $tenant->getLastName())) {
                    $names[] = trim((string) $tenant->getLastName());
                }
            }
            $dossier->setName(mb_substr(implode(' & ', array_filter($names)), 0, 100) ?: $dossier->getName());
        }
    }

    private function dossier(): Dossier
    {
        return $this->repository->find($this->dossierId)
            ?? throw new NotFoundHttpException('Dossier not found.');
    }

    private function person(int $id): DossierPerson
    {
        foreach ($this->dossier()->getPersons() as $person) {
            if ($person->getId() === $id) {
                return $person;
            }
        }

        throw new NotFoundHttpException('Person not found on this dossier.');
    }

    private function resetDraft(): void
    {
        $this->editId = null;
        $this->adding = false;
        $this->errors = [];
        $this->role = '';
        $this->firstName = '';
        $this->lastName = '';
        $this->email = '';
        $this->phone = '';
        $this->language = ContactLanguage::FR->value;
        $this->profession = '';
        $this->noProfession = false;
        $this->employer = '';
        $this->jobTitle = '';
        $this->contractStart = '';
        $this->contractEnd = '';
        $this->trialOver = false;
        $this->monthlyIncome = '';
        $this->birthDate = '';
        $this->nationality = '';
        $this->currentCity = '';
        $this->visaStatus = '';
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_DOSSIERS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
