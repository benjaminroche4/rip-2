<?php

declare(strict_types=1);

namespace App\Dossier\Entity;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'dossier_person')]
class DossierPerson
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: DossierPersonRole::class)]
    #[Assert\NotNull(message: 'admin.dossiers.create.person.role.notNull')]
    private ?DossierPersonRole $role = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'admin.dossiers.create.person.firstName.notBlank')]
    #[Assert\Length(min: 2, max: 50, minMessage: 'admin.dossiers.create.person.firstName.length', maxMessage: 'admin.dossiers.create.person.firstName.length')]
    private ?string $firstName = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'admin.dossiers.create.person.lastName.notBlank')]
    #[Assert\Length(min: 2, max: 50, minMessage: 'admin.dossiers.create.person.lastName.length', maxMessage: 'admin.dossiers.create.person.lastName.length')]
    private ?string $lastName = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'admin.dossiers.create.person.email.notBlank')]
    #[Assert\Email(message: 'admin.dossiers.create.person.email.invalid')]
    #[Assert\Length(max: 180, maxMessage: 'admin.dossiers.create.person.email.length')]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30, maxMessage: 'admin.dossiers.create.person.phone.length')]
    #[Assert\Regex(pattern: '/^\+?\d[\d .\-]*$/', message: 'admin.dossiers.create.person.phone.invalid')]
    private ?string $phone = null;

    // Nullable on the PHP side: the form submits null when the admin has not
    // picked a radio yet, and PropertyAccessor must be able to set it so the
    // NotNull constraint reports a field error instead of a type crash.
    #[ORM\Column(length: 2, enumType: ContactLanguage::class)]
    #[Assert\NotNull(message: 'admin.dossiers.create.person.language.notNull')]
    private ?ContactLanguage $language = ContactLanguage::FR;

    /**
     * Professional situation ("cdi", "cdd", "freelance", "expat",
     * "student", "retired", "other"), optional. Drives the guarantor
     * conversation and the landlord file.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $profession = null;

    /** Net monthly income in €, optional (rent-to-income ratio). */
    #[ORM\Column(nullable: true)]
    private ?int $monthlyIncome = null;

    /** Birth date, optional (landlord file + at-a-glance age). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    /** ISO 3166-1 alpha-2 country code, optional. */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $nationality = null;

    /** City where the person currently lives, free text. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $currentCity = null;

    /** Visa need for the relocation: yes, no, obtained. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $visaStatus = null;

    /**
     * "No professional activity" flag (the spouse works, stay-at-home
     * parent, …): the whole pro pane is hidden and cleared.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $noProfession = false;

    /** Employer name, optional (salaried situations only). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $employer = null;

    /** Job title / activity, optional. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $jobTitle = null;

    /** Contract or activity start date (seniority at a glance). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $contractStartDate = null;

    /** Contract end date, CDD only (does it cover the target lease?). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $contractEndDate = null;

    /** CDI: trial period over ("essai validé"), the landlord's key criterion. */
    #[ORM\Column(options: ['default' => false])]
    private bool $trialPeriodOver = false;

    /**
     * "Locataire principal" tag — the dossier's main contact. Set by
     * DossierCreate right before persist; exactly one tenant carries it.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $primaryContact = false;

    /**
     * Display order within the dossier, set when the person is appended via
     * Dossier::addPerson().
     */
    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: Dossier::class, inversedBy: 'persons')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Dossier $dossier = null;

    /** @var Collection<int, DossierDocument> */
    #[ORM\OneToMany(targetEntity: DossierDocument::class, mappedBy: 'person', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['requestedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $documents;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): ?DossierPersonRole
    {
        return $this->role;
    }

    public function setRole(?DossierPersonRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = '' !== trim((string) $phone) ? $phone : null;

        return $this;
    }

    public function getLanguage(): ?ContactLanguage
    {
        return $this->language;
    }

    public function setLanguage(?ContactLanguage $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getProfession(): ?string
    {
        return $this->profession;
    }

    public function setProfession(?string $profession): static
    {
        $this->profession = $profession;

        return $this;
    }

    public function getMonthlyIncome(): ?int
    {
        return $this->monthlyIncome;
    }

    public function setMonthlyIncome(?int $monthlyIncome): static
    {
        $this->monthlyIncome = $monthlyIncome;

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    public function getNationality(): ?string
    {
        return $this->nationality;
    }

    public function setNationality(?string $nationality): static
    {
        $this->nationality = $nationality;

        return $this;
    }

    public function getCurrentCity(): ?string
    {
        return $this->currentCity;
    }

    public function setCurrentCity(?string $currentCity): static
    {
        $this->currentCity = $currentCity;

        return $this;
    }

    public function getVisaStatus(): ?string
    {
        return $this->visaStatus;
    }

    public function setVisaStatus(?string $visaStatus): static
    {
        $this->visaStatus = $visaStatus;

        return $this;
    }

    public function isNoProfession(): bool
    {
        return $this->noProfession;
    }

    public function setNoProfession(bool $noProfession): static
    {
        $this->noProfession = $noProfession;

        return $this;
    }

    public function getEmployer(): ?string
    {
        return $this->employer;
    }

    public function setEmployer(?string $employer): static
    {
        $this->employer = $employer;

        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): static
    {
        $this->jobTitle = $jobTitle;

        return $this;
    }

    public function getContractStartDate(): ?\DateTimeImmutable
    {
        return $this->contractStartDate;
    }

    public function setContractStartDate(?\DateTimeImmutable $contractStartDate): static
    {
        $this->contractStartDate = $contractStartDate;

        return $this;
    }

    public function getContractEndDate(): ?\DateTimeImmutable
    {
        return $this->contractEndDate;
    }

    public function setContractEndDate(?\DateTimeImmutable $contractEndDate): static
    {
        $this->contractEndDate = $contractEndDate;

        return $this;
    }

    public function isTrialPeriodOver(): bool
    {
        return $this->trialPeriodOver;
    }

    public function setTrialPeriodOver(bool $trialPeriodOver): static
    {
        $this->trialPeriodOver = $trialPeriodOver;

        return $this;
    }

    public function isPrimaryContact(): bool
    {
        return $this->primaryContact;
    }

    public function setPrimaryContact(bool $primaryContact): static
    {
        $this->primaryContact = $primaryContact;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /**
     * @return Collection<int, DossierDocument>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(DossierDocument $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setPerson($this);
        }

        return $this;
    }

    public function getDossier(): ?Dossier
    {
        return $this->dossier;
    }

    public function setDossier(?Dossier $dossier): static
    {
        $this->dossier = $dossier;

        return $this;
    }
}
