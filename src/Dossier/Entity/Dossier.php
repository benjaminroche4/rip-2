<?php

declare(strict_types=1);

namespace App\Dossier\Entity;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\DossierStatus;
use App\Dossier\Domain\DossierStep;
use App\Dossier\Repository\DossierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: DossierRepository::class)]
#[ORM\Table(name: 'dossier')]
class Dossier
{
    /**
     * Sliding validity window of the pairing code, in days, re-armed by
     * every email embedding the code (see $pairingCodeSentAt). Shared by
     * the public deposit pairing check and the back-office expiry alert.
     */
    public const PAIRING_CODE_TTL_DAYS = 90;

    public const MAX_PERSONS = 4;
    public const MIN_PERSONS = 1;
    /** Max persons per role: 2 tenants + 2 follow-up requests. */
    public const MAX_PER_ROLE = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'admin.dossiers.create.name.notBlank')]
    #[Assert\Length(min: 2, max: 100, minMessage: 'admin.dossiers.create.name.length', maxMessage: 'admin.dossiers.create.name.length')]
    private ?string $name = null;

    /**
     * Public dossier number, random, "DS-087526". Set by DossierNumberGenerator
     * right before persist — not user input, hence no Assert constraints.
     */
    #[ORM\Column(length: 9, unique: true)]
    private ?string $reference = null;

    /**
     * Pairing code, random letters + digits, "ABE78L" (unambiguous alphabet:
     * no O/0 nor I/1 lookalikes). Also set by DossierNumberGenerator.
     */
    #[ORM\Column(length: 6, unique: true)]
    private ?string $pairingCode = null;

    /**
     * Last arming of the pairing code: dossier creation (or code rotation),
     * then every email embedding the code re-arms it. The public deposit
     * page refuses a code older than PAIRING_CODE_TTL, exactly like an
     * unknown code; null means "never armed" and is refused too.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pairingCodeSentAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Fully automatic: names the step currently pending, kept aligned with
     * the validated steps by DossierStatusAdvancer. "Closed" is derived from
     * closedAt by getEffectiveStatus(), never stored here.
     */
    #[ORM\Column(length: 20, enumType: DossierStatus::class, options: ['default' => DossierStatus::Persons])]
    private DossierStatus $status = DossierStatus::Persons;

    /**
     * Closure timestamp: a closed dossier has its deposited files purged,
     * its pairing code rotated and the public deposit page refuses it.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /**
     * Verrou temporaire de l'espace de dépôt public : posé/levé par le
     * staff depuis la card Dossier. Verrouillé, l'appairage répond "accès
     * verrouillé, réessayez plus tard" et les sessions appairées retombent
     * sur le formulaire. Rien n'est purgé ni tourné (contrairement à la
     * clôture) : c'est une pause, pas une fin.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $depositLockedAt = null;

    /** AI quick recap (JSON: summary, attentionPoints, nextAction), generated on demand. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $recapJson = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recapGeneratedAt = null;

    /**
     * Reference of the contact request this dossier was converted from, or
     * null for dossiers created from scratch. Displayed as the origin entry
     * of the follow-up thread, linking back to the contact page.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $sourceContactReference = null;

    /**
     * Formule choisie ("accompagne" | "confie") : copiée du contact à la
     * conversion, choisie à la main sur un dossier créé de zéro.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $offer = null;

    /**
     * Staff member in charge of the dossier ("responsable de dossier").
     * Optional; survives the user's deletion as an unassigned dossier.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $manager = null;

    /**
     * Id of this dossier's root folder in the agency Shared Drive (mode
     * DOSSIER_STORAGE=drive), or null when Drive is off or the folder has not
     * been provisioned yet. Per-person pieces live in sub-folders (see
     * DossierPerson::$driveFolderId).
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $driveFolderId = null;

    /**
     * Id of the Drive permission granting the current manager read access to
     * the dossier folder, kept so it can be revoked when the manager changes.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $driveManagerPermissionId = null;

    /**
     * Steps of the path explicitly validated by the staff ("Valider" button
     * at the bottom of each step card), as DossierStep values. Sequential by
     * construction: DossierStepValidator only accepts the first step still
     * open, so the list is always a prefix of DossierStep::ordered().
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $validatedSteps = null;

    /**
     * Ordered list of persons attached to the dossier. Bound to the
     * LiveCollectionType in DossierType, hence allow_add/remove via the
     * LiveCollectionTrait.
     *
     * @var Collection<int, DossierPerson>
     */
    #[ORM\OneToMany(
        targetEntity: DossierPerson::class,
        mappedBy: 'dossier',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Count(
        min: self::MIN_PERSONS,
        max: self::MAX_PERSONS,
        minMessage: 'admin.dossiers.create.persons.min',
        maxMessage: 'admin.dossiers.create.persons.max',
    )]
    #[Assert\Valid]
    private Collection $persons;

    /**
     * Search criteria ("Recherche" module), seeded from the contact at
     * conversion time. Nullable: dossiers created from scratch start empty.
     */
    #[ORM\OneToOne(targetEntity: DossierSearch::class, mappedBy: 'dossier', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?DossierSearch $search = null;

    /**
     * Follow-up thread ("fil de suivi"), oldest first.
     *
     * @var Collection<int, DossierNote>
     */
    #[ORM\OneToMany(targetEntity: DossierNote::class, mappedBy: 'dossier', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $notes;

    public function __construct()
    {
        $this->persons = new ArrayCollection();
        $this->notes = new ArrayCollection();
    }

    /**
     * Household composition rules: at least one tenant (the dossier's main
     * contact), and never more than MAX_PER_ROLE persons of the same role.
     * The primary flag itself is set by DossierCreate right before persist,
     * so it is deliberately not validated here.
     */
    #[Assert\Callback]
    public function validateComposition(ExecutionContextInterface $context): void
    {
        $tenants = 0;
        $followUps = 0;
        foreach ($this->persons as $person) {
            match ($person->getRole()) {
                DossierPersonRole::TENANT => ++$tenants,
                DossierPersonRole::FOLLOW_UP => ++$followUps,
                null => 0,
            };
        }

        if (0 === $tenants) {
            $context->buildViolation('admin.dossiers.create.persons.tenantRequired')
                ->atPath('persons')
                ->addViolation();
        }
        if ($tenants > self::MAX_PER_ROLE) {
            $context->buildViolation('admin.dossiers.create.persons.maxTenants')
                ->atPath('persons')
                ->addViolation();
        }
        if ($followUps > self::MAX_PER_ROLE) {
            $context->buildViolation('admin.dossiers.create.persons.maxFollowUps')
                ->atPath('persons')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getPairingCode(): ?string
    {
        return $this->pairingCode;
    }

    public function setPairingCode(?string $pairingCode): static
    {
        $this->pairingCode = $pairingCode;

        return $this;
    }

    public function getPairingCodeSentAt(): ?\DateTimeImmutable
    {
        return $this->pairingCodeSentAt;
    }

    public function setPairingCodeSentAt(?\DateTimeImmutable $pairingCodeSentAt): static
    {
        $this->pairingCodeSentAt = $pairingCodeSentAt;

        return $this;
    }

    public function getSearch(): ?DossierSearch
    {
        return $this->search;
    }

    public function setSearch(?DossierSearch $search): static
    {
        $search?->setDossier($this);
        $this->search = $search;

        return $this;
    }

    /**
     * @return Collection<int, DossierNote>
     */
    public function getNotes(): Collection
    {
        return $this->notes;
    }

    public function addNote(DossierNote $note): static
    {
        if (!$this->notes->contains($note)) {
            $note->setDossier($this);
            $this->notes->add($note);
        }

        return $this;
    }

    public function getSourceContactReference(): ?string
    {
        return $this->sourceContactReference;
    }

    public function setSourceContactReference(?string $sourceContactReference): static
    {
        $this->sourceContactReference = $sourceContactReference;

        return $this;
    }

    public function getManager(): ?User
    {
        return $this->manager;
    }

    public function setManager(?User $manager): static
    {
        $this->manager = $manager;

        return $this;
    }

    public function getDriveFolderId(): ?string
    {
        return $this->driveFolderId;
    }

    public function setDriveFolderId(?string $driveFolderId): static
    {
        $this->driveFolderId = $driveFolderId;

        return $this;
    }

    public function getDriveManagerPermissionId(): ?string
    {
        return $this->driveManagerPermissionId;
    }

    public function setDriveManagerPermissionId(?string $driveManagerPermissionId): static
    {
        $this->driveManagerPermissionId = $driveManagerPermissionId;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getValidatedSteps(): array
    {
        return $this->validatedSteps ?? [];
    }

    public function isStepValidated(DossierStep $step): bool
    {
        return \in_array($step->value, $this->getValidatedSteps(), true);
    }

    public function addValidatedStep(DossierStep $step): static
    {
        if (!$this->isStepValidated($step)) {
            $this->validatedSteps = [...$this->getValidatedSteps(), $step->value];
        }

        return $this;
    }

    public function removeValidatedStep(DossierStep $step): static
    {
        $steps = array_values(array_diff($this->getValidatedSteps(), [$step->value]));
        $this->validatedSteps = [] !== $steps ? $steps : null;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isDepositLocked(): bool
    {
        return null !== $this->depositLockedAt;
    }

    public function getDepositLockedAt(): ?\DateTimeImmutable
    {
        return $this->depositLockedAt;
    }

    public function setDepositLockedAt(?\DateTimeImmutable $depositLockedAt): static
    {
        $this->depositLockedAt = $depositLockedAt;

        return $this;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function getRecapJson(): ?string
    {
        return $this->recapJson;
    }

    public function getRecapGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->recapGeneratedAt;
    }

    public function setRecap(?string $recapJson, ?\DateTimeImmutable $generatedAt): static
    {
        $this->recapJson = $recapJson;
        $this->recapGeneratedAt = $generatedAt;

        return $this;
    }

    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }

    public function getStatus(): DossierStatus
    {
        return $this->status;
    }

    public function setStatus(DossierStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** A requested piece not validated yet keeps the dossier incomplete. */
    public function hasPendingDocuments(): bool
    {
        foreach ($this->persons as $person) {
            foreach ($person->getDocuments() as $document) {
                if (DossierDocumentStatus::Validated !== $document->getStatus()) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getEffectiveStatus(): DossierStatus
    {
        return DossierStatus::effective($this->status, $this->isClosed());
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, DossierPerson>
     */
    public function getPersons(): Collection
    {
        return $this->persons;
    }

    public function addPerson(DossierPerson $person): static
    {
        if (!$this->persons->contains($person)) {
            $person->setDossier($this);
            $person->setPosition($this->persons->count());
            $this->persons->add($person);
        }

        return $this;
    }

    public function removePerson(DossierPerson $person): static
    {
        if ($this->persons->removeElement($person)) {
            if ($person->getDossier() === $this) {
                $person->setDossier(null);
            }
        }

        return $this;
    }

    public function getOffer(): ?string
    {
        return $this->offer;
    }

    public function setOffer(?string $offer): static
    {
        $this->offer = $offer;

        return $this;
    }
}
