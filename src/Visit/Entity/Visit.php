<?php

declare(strict_types=1);

namespace App\Visit\Entity;

use App\Dossier\Entity\Dossier;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\Visit\Repository\VisitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Property visit scheduled for a dossier: a date, an address, and optionally
 * the real-estate agent (annuaire) who shows the property.
 */
#[ORM\Entity(repositoryClass: VisitRepository::class)]
#[ORM\Table(name: 'visit')]
class Visit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Visits die with their dossier — they make no sense without it. */
    #[ORM\ManyToOne(targetEntity: Dossier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'admin.visits.create.dossier.notNull')]
    private ?Dossier $dossier = null;

    /** Nature of the visit (first visit, inventory, key handover...). */
    #[ORM\Column(length: 30, enumType: \App\Visit\Domain\VisitType::class)]
    private \App\Visit\Domain\VisitType $type = \App\Visit\Domain\VisitType::PropertyVisit;

    /** Outcome: planned until closed as done / cancelled / no-show. */
    #[ORM\Column(length: 20, enumType: \App\Visit\Domain\VisitStatus::class)]
    private \App\Visit\Domain\VisitStatus $status = \App\Visit\Domain\VisitStatus::Planned;

    /** Listing URL (SeLoger, PAP, agency site...) to review the property. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(message: 'admin.visits.create.listingUrl.invalid', requireTld: true)]
    #[Assert\Length(max: 500, maxMessage: 'admin.visits.create.listingUrl.length')]
    private ?string $listingUrl = null;

    /** Estimated duration in minutes (tour planning, future agenda push). */
    #[ORM\Column(options: ['default' => 30])]
    #[Assert\Choice(choices: [15, 30, 45, 60], message: 'admin.visits.create.duration.invalid')]
    private int $durationMinutes = 30;

    /** The tenant attends the visit; unticked by default (the team books
        many visits where the client only sees the report). */
    #[ORM\Column(options: ['default' => false])]
    private bool $clientPresent = false;

    /** Post-visit report, filled once the visit is done. */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT, nullable: true)]
    private ?string $report = null;

    /** Client temperature after a done visit. */
    #[ORM\Column(length: 10, nullable: true, enumType: \App\Visit\Domain\ClientFeeling::class)]
    private ?\App\Visit\Domain\ClientFeeling $clientFeeling = null;

    /** "Les plus du logement" cochés dans le compte-rendu : valeurs de
        PropertyHighlight, stockées en JSON dans l'ordre stable de l'enum. */
    #[ORM\Column(nullable: true)]
    private ?array $reportHighlights = null;

    /** Note destinée au client après la visite, rédigée ou générée par IA. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $clientNote = null;

    /** Traduction anglaise de la note client, générée au moment de l'envoi
        quand un destinataire est anglophone (traçabilité seulement, jamais
        affichée; écrasée à chaque envoi car la note FR a pu être retouchée
        entre deux; null quand la traduction a échoué, l'email anglophone
        étant alors parti avec le texte français). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $clientNoteEn = null;

    /** Horodatage du dernier changement de décision client (pour "en attente depuis"). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $clientDecisionAt = null;

    /** Dernier envoi de la note client par email (null = jamais envoyée).
        Volontairement conservé si la note est modifiée après coup. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $clientNoteSentAt = null;

    /** Rappel staff "échéance de réflexion dépassée" déjà envoyé (idempotence
        du cron app:visits:send-decision-reminders). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decisionReminderSentAt = null;

    /** Rappel staff "compte-rendu à remplir" déjà envoyé (idempotence du
        même cron); remis à null quand la visite quitte Effectuée pour que le
        re-passage en Effectuée réarme un rappel. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reportReminderSentAt = null;

    /** Décision du client sur le bien (réfléchit / se positionne / refuse). */
    #[ORM\Column(length: 20, nullable: true, enumType: \App\Visit\Domain\ClientDecision::class)]
    private ?\App\Visit\Domain\ClientDecision $clientDecision = null;

    /** Issue de la candidature déposée (bailleur/propriétaire/agence);
        null = en attente. N'a de sens que si le client s'est positionné. */
    #[ORM\Column(length: 20, nullable: true, enumType: \App\Visit\Domain\ApplicationOutcome::class)]
    private ?\App\Visit\Domain\ApplicationOutcome $applicationOutcome = null;

    /** Échéance de réflexion (date seule) quand le client réfléchit;
        remise à null dès que la décision quitte "Réfléchit". */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decisionDeadline = null;

    /** Origine du refus (bailleur/propriétaire/agence ou le client) quand la
        décision est "Refuse"; remise à null dès qu'elle change. */
    #[ORM\Column(length: 20, nullable: true, enumType: \App\Visit\Domain\RefusalOrigin::class)]
    private ?\App\Visit\Domain\RefusalOrigin $refusalOrigin = null;

    /**
     * "Le bien en détail" section: optional descriptive facts about the
     * visited property. All nullable, none required to book the visit.
     */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'admin.visits.create.propertyDetails.surface.invalid')]
    #[Assert\LessThanOrEqual(2000, message: 'admin.visits.create.propertyDetails.surface.invalid')]
    private ?float $surface = null;

    /** 0 = ground floor. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'admin.visits.create.propertyDetails.floor.invalid')]
    private ?int $floor = null;

    /** Same vocabulary as the dossier search chips (studio, t1, ...). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $propertyKind = null;

    /** 'furnished' or 'unfurnished', same codes as the dossier search. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $furnishing = null;

    #[ORM\Column(length: 20, nullable: true, enumType: \App\Visit\Domain\LeaseType::class)]
    private ?\App\Visit\Domain\LeaseType $leaseType = null;

    /** Monthly rent excluding charges, in euros. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'admin.visits.create.propertyDetails.amount.invalid')]
    #[Assert\LessThanOrEqual(100000, message: 'admin.visits.create.propertyDetails.amount.invalid')]
    private ?float $rentExcludingCharges = null;

    /** Monthly charges, in euros. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'admin.visits.create.propertyDetails.amount.invalid')]
    #[Assert\LessThanOrEqual(100000, message: 'admin.visits.create.propertyDetails.amount.invalid')]
    private ?float $charges = null;

    /** Loyer charges comprises (true) ou hors charges (false/null = HC). */
    #[ORM\Column(nullable: true)]
    private ?bool $rentChargesIncluded = null;

    /** Free note from the operator (access code, contact on site...). */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: 'admin.visits.create.note.length')]
    private ?string $note = null;

    /**
     * Property photos taken for the visit, oldest first.
     *
     * @var Collection<int, VisitPhoto>
     */
    #[ORM\OneToMany(targetEntity: VisitPhoto::class, mappedBy: 'visit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    public function __construct()
    {
        $this->photos = new ArrayCollection();
    }

    /** Public reference shown in the admin ("VS-087526"). */
    #[ORM\Column(length: 9, unique: true)]
    private string $reference;

    /** Operator who booked the visit (autofilled at creation). */
    #[ORM\ManyToOne(targetEntity: \App\Auth\Entity\User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?\App\Auth\Entity\User $bookedBy = null;

    /** Team member performing the visit; survives the account's deletion. */
    #[ORM\ManyToOne(targetEntity: \App\Auth\Entity\User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?\App\Auth\Entity\User $assignee = null;

    /** Optional link to the agents directory; survives the agent's deletion. */
    #[ORM\ManyToOne(targetEntity: RealEstateAgent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RealEstateAgent $agent = null;

    /** Europe/Paris local time (form model timezone). Le refus du passé ne
        vaut qu'à la création (groupe visit_create) : une visite passée doit
        rester éditable, le déplacement vers le passé est regardé dans
        VisitForm::create(). Même grâce de 10 minutes que la contrainte du
        formulaire (VisitType), pour que "tout de suite" reste réservable. */
    #[ORM\Column]
    #[Assert\NotNull(message: 'admin.visits.create.scheduledAt.notNull')]
    #[Assert\GreaterThanOrEqual('-10 minutes', message: 'admin.visits.create.scheduledAt.past', groups: ['visit_create'])]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'admin.visits.create.address.notBlank')]
    #[Assert\Length(max: 255, maxMessage: 'admin.visits.create.address.length')]
    private ?string $address = null;

    /**
     * Coordinates resolved by AddressGeocoder at creation time. Nullable: a
     * failed geocoding never blocks the visit, it just misses from the map.
     */
    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /** Dernière modification depuis la fiche (null = jamais retouchée). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** Instantané du dernier modificateur (nom, sinon email) : survit à la
        suppression du compte staff, comme sur les fiches agents. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $updatedByName = null;

    /** Instantané de la photo de profil du dernier modificateur. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $updatedByAvatar = null;

    /**
     * Google Calendar mirror (VisitCalendarSync): id of the event in the
     * central agenda, id of the twin event in the assignee's own agenda,
     * and the assignee email that personal event was created under (needed
     * to delete it from the right agenda when the assignee changes).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $calendarCentralEventId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $calendarAssigneeEventId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $calendarAssigneeEmail = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getAgent(): ?RealEstateAgent
    {
        return $this->agent;
    }

    public function getSurface(): ?float
    {
        return $this->surface;
    }

    public function setSurface(?float $surface): static
    {
        $this->surface = $surface;

        return $this;
    }

    public function getFloor(): ?int
    {
        return $this->floor;
    }

    public function setFloor(?int $floor): static
    {
        $this->floor = $floor;

        return $this;
    }

    public function getPropertyKind(): ?string
    {
        return $this->propertyKind;
    }

    public function setPropertyKind(?string $propertyKind): static
    {
        $this->propertyKind = $propertyKind;

        return $this;
    }

    public function getFurnishing(): ?string
    {
        return $this->furnishing;
    }

    public function setFurnishing(?string $furnishing): static
    {
        $this->furnishing = $furnishing;

        return $this;
    }

    public function getLeaseType(): ?\App\Visit\Domain\LeaseType
    {
        return $this->leaseType;
    }

    public function setLeaseType(?\App\Visit\Domain\LeaseType $leaseType): static
    {
        $this->leaseType = $leaseType;

        return $this;
    }

    public function getRentExcludingCharges(): ?float
    {
        return $this->rentExcludingCharges;
    }

    public function setRentExcludingCharges(?float $rentExcludingCharges): static
    {
        $this->rentExcludingCharges = $rentExcludingCharges;

        return $this;
    }

    public function getCharges(): ?float
    {
        return $this->charges;
    }

    public function setCharges(?float $charges): static
    {
        $this->charges = $charges;

        return $this;
    }

    public function getRentChargesIncluded(): ?bool
    {
        return $this->rentChargesIncluded;
    }

    public function setRentChargesIncluded(?bool $rentChargesIncluded): static
    {
        $this->rentChargesIncluded = $rentChargesIncluded;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getType(): \App\Visit\Domain\VisitType
    {
        return $this->type;
    }

    public function setType(\App\Visit\Domain\VisitType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): \App\Visit\Domain\VisitStatus
    {
        return $this->status;
    }

    public function setStatus(\App\Visit\Domain\VisitStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getListingUrl(): ?string
    {
        return $this->listingUrl;
    }

    public function setListingUrl(?string $listingUrl): static
    {
        $this->listingUrl = $listingUrl;

        return $this;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $durationMinutes): static
    {
        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    public function isClientPresent(): bool
    {
        return $this->clientPresent;
    }

    public function setClientPresent(bool $clientPresent): static
    {
        $this->clientPresent = $clientPresent;

        return $this;
    }

    public function getReport(): ?string
    {
        return $this->report;
    }

    public function setReport(?string $report): static
    {
        $this->report = $report;

        return $this;
    }

    /**
     * @return list<\App\Visit\Domain\PropertyHighlight> in stable enum order
     */
    public function getReportHighlights(): array
    {
        $stored = $this->reportHighlights ?? [];

        // L'ordre d'affichage est celui de l'enum, jamais celui des clics.
        return array_values(array_filter(
            \App\Visit\Domain\PropertyHighlight::cases(),
            static fn (\App\Visit\Domain\PropertyHighlight $case): bool => \in_array($case->value, $stored, true),
        ));
    }

    public function hasReportHighlight(\App\Visit\Domain\PropertyHighlight $highlight): bool
    {
        return \in_array($highlight->value, $this->reportHighlights ?? [], true);
    }

    /**
     * @param list<\App\Visit\Domain\PropertyHighlight> $highlights
     */
    public function setReportHighlights(array $highlights): static
    {
        $values = array_values(array_intersect(
            array_column(\App\Visit\Domain\PropertyHighlight::cases(), 'value'),
            array_map(static fn (\App\Visit\Domain\PropertyHighlight $h): string => $h->value, $highlights),
        ));
        $this->reportHighlights = [] !== $values ? $values : null;

        return $this;
    }

    public function getClientNote(): ?string
    {
        return $this->clientNote;
    }

    public function setClientNote(?string $clientNote): static
    {
        $this->clientNote = $clientNote;

        return $this;
    }

    public function getClientNoteEn(): ?string
    {
        return $this->clientNoteEn;
    }

    public function setClientNoteEn(?string $clientNoteEn): static
    {
        $this->clientNoteEn = $clientNoteEn;

        return $this;
    }

    public function getClientFeeling(): ?\App\Visit\Domain\ClientFeeling
    {
        return $this->clientFeeling;
    }

    public function setClientFeeling(?\App\Visit\Domain\ClientFeeling $clientFeeling): static
    {
        $this->clientFeeling = $clientFeeling;

        return $this;
    }

    public function getClientDecision(): ?\App\Visit\Domain\ClientDecision
    {
        return $this->clientDecision;
    }

    public function setClientDecision(?\App\Visit\Domain\ClientDecision $clientDecision): static
    {
        $this->clientDecision = $clientDecision;

        return $this;
    }

    public function getClientDecisionAt(): ?\DateTimeImmutable
    {
        return $this->clientDecisionAt;
    }

    public function setClientDecisionAt(?\DateTimeImmutable $clientDecisionAt): static
    {
        $this->clientDecisionAt = $clientDecisionAt;

        return $this;
    }

    public function getClientNoteSentAt(): ?\DateTimeImmutable
    {
        return $this->clientNoteSentAt;
    }

    public function setClientNoteSentAt(?\DateTimeImmutable $clientNoteSentAt): static
    {
        $this->clientNoteSentAt = $clientNoteSentAt;

        return $this;
    }

    public function getDecisionReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->decisionReminderSentAt;
    }

    public function setDecisionReminderSentAt(?\DateTimeImmutable $decisionReminderSentAt): static
    {
        $this->decisionReminderSentAt = $decisionReminderSentAt;

        return $this;
    }

    public function getReportReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->reportReminderSentAt;
    }

    public function setReportReminderSentAt(?\DateTimeImmutable $reportReminderSentAt): static
    {
        $this->reportReminderSentAt = $reportReminderSentAt;

        return $this;
    }

    public function getDecisionDeadline(): ?\DateTimeImmutable
    {
        return $this->decisionDeadline;
    }

    public function setDecisionDeadline(?\DateTimeImmutable $decisionDeadline): static
    {
        $this->decisionDeadline = $decisionDeadline;

        return $this;
    }

    public function getRefusalOrigin(): ?\App\Visit\Domain\RefusalOrigin
    {
        return $this->refusalOrigin;
    }

    public function setRefusalOrigin(?\App\Visit\Domain\RefusalOrigin $refusalOrigin): static
    {
        $this->refusalOrigin = $refusalOrigin;

        return $this;
    }

    public function getApplicationOutcome(): ?\App\Visit\Domain\ApplicationOutcome
    {
        return $this->applicationOutcome;
    }

    public function setApplicationOutcome(?\App\Visit\Domain\ApplicationOutcome $applicationOutcome): static
    {
        $this->applicationOutcome = $applicationOutcome;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getBookedBy(): ?\App\Auth\Entity\User
    {
        return $this->bookedBy;
    }

    public function setBookedBy(?\App\Auth\Entity\User $bookedBy): static
    {
        $this->bookedBy = $bookedBy;

        return $this;
    }

    public function getAssignee(): ?\App\Auth\Entity\User
    {
        return $this->assignee;
    }

    public function setAssignee(?\App\Auth\Entity\User $assignee): static
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function setAgent(?RealEstateAgent $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    /**
     * @return Collection<int, VisitPhoto>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(VisitPhoto $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setVisit($this);
        }

        return $this;
    }

    public function removePhoto(VisitPhoto $photo): static
    {
        $this->photos->removeElement($photo);

        return $this;
    }

    public function getCalendarCentralEventId(): ?string
    {
        return $this->calendarCentralEventId;
    }

    public function setCalendarCentralEventId(?string $calendarCentralEventId): static
    {
        $this->calendarCentralEventId = $calendarCentralEventId;

        return $this;
    }

    public function getCalendarAssigneeEventId(): ?string
    {
        return $this->calendarAssigneeEventId;
    }

    public function setCalendarAssigneeEventId(?string $calendarAssigneeEventId): static
    {
        $this->calendarAssigneeEventId = $calendarAssigneeEventId;

        return $this;
    }

    public function getCalendarAssigneeEmail(): ?string
    {
        return $this->calendarAssigneeEmail;
    }

    public function setCalendarAssigneeEmail(?string $calendarAssigneeEmail): static
    {
        $this->calendarAssigneeEmail = $calendarAssigneeEmail;

        return $this;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedByName(): ?string
    {
        return $this->updatedByName;
    }

    public function getUpdatedByAvatar(): ?string
    {
        return $this->updatedByAvatar;
    }

    /** Pose les trois champs d'un coup : le point d'entrée unique des mutations. */
    public function touchBy(?string $name, ?string $avatar): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedByName = $name;
        $this->updatedByAvatar = $avatar;

        return $this;
    }
}
