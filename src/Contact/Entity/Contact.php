<?php

namespace App\Contact\Entity;

use App\Auth\Entity\User;
use App\Contact\Domain\ClosureReason;
use App\Contact\Domain\ContactSource;
use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\GuarantorType;
use App\Contact\Domain\LeadSource;
use App\Contact\Domain\NextStep;
use App\Contact\Domain\RecontactChannel;
use App\Contact\Domain\StayDuration;
use App\Contact\Repository\ContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContactRepository::class)]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    private ?string $lastName = null;

    #[ORM\Column(length: 254)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(length: 100)]
    private ?string $helpType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 5)]
    private ?string $lang = null;

    /**
     * Public reference shown in the admin ("CT-082820") and usable in email
     * exchanges. Random rather than the auto-increment id so the submission
     * volume isn't guessable from the outside.
     */
    #[ORM\Column(length: 9, unique: true)]
    private string $reference;

    #[ORM\Column(length: 20, enumType: ContactStatus::class)]
    private ContactStatus $status = ContactStatus::New;

    /** Display-name snapshot of the admin who last changed the status. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $statusChangedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $statusChangedAt = null;

    /** Avatar filename snapshot of the admin who last changed the status. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $statusChangedByAvatar = null;

    /**
     * When the submission first left "new". Set once, never overwritten:
     * this is the first-response timestamp behind the SLA KPIs.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firstTreatedAt = null;

    /**
     * Package chosen on the public form ("accompagne" or "confie"), only
     * set when the help type is the housing search.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $offer = null;

    /** Lead quality, 1-5 stars, filled by the admin after a status change. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $leadRating = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $leadNote = null;

    /** Preferred channel to get back to the lead, picked while qualifying. */
    #[ORM\Column(length: 20, nullable: true, enumType: RecontactChannel::class)]
    private ?RecontactChannel $recontactChannel = null;

    /** Team member following the lead. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    /** Planned recall date, the actionable side of the "to recall" status. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recallAt = null;

    /** Recall reminder emails already sent (reset when the recall moves). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recallReminderDaySentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recallReminderHourSentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recallReminderSoonSentAt = null;

    /** Why the submission was closed/unqualified. */
    #[ORM\Column(length: 30, nullable: true, enumType: ClosureReason::class)]
    private ?ClosureReason $closureReason = null;

    /** Planned next action while the request is in progress. */
    #[ORM\Column(length: 30, nullable: true, enumType: NextStep::class)]
    private ?NextStep $nextStep = null;

    /** How the lead found the agency. */
    #[ORM\Column(length: 30, nullable: true, enumType: LeadSource::class)]
    private ?LeadSource $leadSource = null;

    /** How the request reached us (form by default, set for manual intakes). */
    #[ORM\Column(length: 15, enumType: ContactSource::class, options: ['default' => 'form'])]
    private ContactSource $source = ContactSource::Form;

    /** Structured housing project, extracted by the admin while qualifying. */
    #[ORM\Column(nullable: true)]
    private ?int $projectBudget = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $projectAreas = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $projectMoveInAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $projectPropertyType = null;

    /** Intended length of stay (short/medium/long term). */
    #[ORM\Column(length: 10, nullable: true, enumType: StayDuration::class)]
    private ?StayDuration $projectStayDuration = null;

    /** Furnished / unfurnished, CSV of Furnishing values (both possible). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $projectFurnishing = null;

    /** Kind of guarantor the prospect can provide. */
    #[ORM\Column(length: 10, nullable: true, enumType: GuarantorType::class)]
    private ?GuarantorType $projectGuarantorType = null;

    /** Free-form note on the housing project. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $projectNote = null;

    public function __construct()
    {
        $this->reference = 'CT-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function getStatus(): ContactStatus
    {
        return $this->status;
    }

    public function setStatus(ContactStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusChangedBy(): ?string
    {
        return $this->statusChangedBy;
    }

    public function setStatusChangedBy(?string $statusChangedBy): static
    {
        $this->statusChangedBy = $statusChangedBy;

        return $this;
    }

    public function getStatusChangedAt(): ?\DateTimeImmutable
    {
        return $this->statusChangedAt;
    }

    public function getStatusChangedByAvatar(): ?string
    {
        return $this->statusChangedByAvatar;
    }

    public function getFirstTreatedAt(): ?\DateTimeImmutable
    {
        return $this->firstTreatedAt;
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

    public function getLeadRating(): ?int
    {
        return $this->leadRating;
    }

    public function setLeadRating(?int $leadRating): static
    {
        $this->leadRating = $leadRating;

        return $this;
    }

    public function getLeadNote(): ?string
    {
        return $this->leadNote;
    }

    public function getRecontactChannel(): ?RecontactChannel
    {
        return $this->recontactChannel;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    public function getRecallAt(): ?\DateTimeImmutable
    {
        return $this->recallAt;
    }

    public function setRecallAt(?\DateTimeImmutable $recallAt): static
    {
        $this->recallAt = $recallAt;

        return $this;
    }

    public function getRecallReminderDaySentAt(): ?\DateTimeImmutable
    {
        return $this->recallReminderDaySentAt;
    }

    public function setRecallReminderDaySentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->recallReminderDaySentAt = $sentAt;

        return $this;
    }

    public function getRecallReminderHourSentAt(): ?\DateTimeImmutable
    {
        return $this->recallReminderHourSentAt;
    }

    public function setRecallReminderHourSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->recallReminderHourSentAt = $sentAt;

        return $this;
    }

    public function getRecallReminderSoonSentAt(): ?\DateTimeImmutable
    {
        return $this->recallReminderSoonSentAt;
    }

    public function setRecallReminderSoonSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->recallReminderSoonSentAt = $sentAt;

        return $this;
    }

    public function getClosureReason(): ?ClosureReason
    {
        return $this->closureReason;
    }

    public function setClosureReason(?ClosureReason $closureReason): static
    {
        $this->closureReason = $closureReason;

        return $this;
    }

    public function getNextStep(): ?NextStep
    {
        return $this->nextStep;
    }

    public function setNextStep(?NextStep $nextStep): static
    {
        $this->nextStep = $nextStep;

        return $this;
    }

    public function getLeadSource(): ?LeadSource
    {
        return $this->leadSource;
    }

    public function setLeadSource(?LeadSource $leadSource): static
    {
        $this->leadSource = $leadSource;

        return $this;
    }

    public function getProjectBudget(): ?int
    {
        return $this->projectBudget;
    }

    public function setProjectBudget(?int $projectBudget): static
    {
        $this->projectBudget = $projectBudget;

        return $this;
    }

    public function getProjectAreas(): ?string
    {
        return $this->projectAreas;
    }

    public function setProjectAreas(?string $projectAreas): static
    {
        $this->projectAreas = $projectAreas;

        return $this;
    }

    public function getProjectMoveInAt(): ?\DateTimeImmutable
    {
        return $this->projectMoveInAt;
    }

    public function setProjectMoveInAt(?\DateTimeImmutable $projectMoveInAt): static
    {
        $this->projectMoveInAt = $projectMoveInAt;

        return $this;
    }

    public function getProjectPropertyType(): ?string
    {
        return $this->projectPropertyType;
    }

    public function setProjectPropertyType(?string $projectPropertyType): static
    {
        $this->projectPropertyType = $projectPropertyType;

        return $this;
    }

    public function getProjectStayDuration(): ?StayDuration
    {
        return $this->projectStayDuration;
    }

    public function setProjectStayDuration(?StayDuration $projectStayDuration): static
    {
        $this->projectStayDuration = $projectStayDuration;

        return $this;
    }

    public function getProjectFurnishing(): ?string
    {
        return $this->projectFurnishing;
    }

    public function setProjectFurnishing(?string $projectFurnishing): static
    {
        $this->projectFurnishing = $projectFurnishing;

        return $this;
    }

    public function getProjectGuarantorType(): ?GuarantorType
    {
        return $this->projectGuarantorType;
    }

    public function setProjectGuarantorType(?GuarantorType $projectGuarantorType): static
    {
        $this->projectGuarantorType = $projectGuarantorType;

        return $this;
    }

    public function getProjectNote(): ?string
    {
        return $this->projectNote;
    }

    public function setProjectNote(?string $projectNote): static
    {
        $this->projectNote = $projectNote;

        return $this;
    }

    public function setRecontactChannel(?RecontactChannel $recontactChannel): static
    {
        $this->recontactChannel = $recontactChannel;

        return $this;
    }

    public function setLeadNote(?string $leadNote): static
    {
        $this->leadNote = $leadNote;

        return $this;
    }

    public function setFirstTreatedAt(?\DateTimeImmutable $firstTreatedAt): static
    {
        $this->firstTreatedAt = $firstTreatedAt;

        return $this;
    }

    public function setStatusChangedByAvatar(?string $statusChangedByAvatar): static
    {
        $this->statusChangedByAvatar = $statusChangedByAvatar;

        return $this;
    }

    public function setStatusChangedAt(?\DateTimeImmutable $statusChangedAt): static
    {
        $this->statusChangedAt = $statusChangedAt;

        return $this;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): static
    {
        // Always leads with a capital, whatever was typed ("acme corp" →
        // "Acme corp", "SNCF" stays untouched).
        $this->company = null !== $company && '' !== $company ? mb_ucfirst($company) : $company;

        return $this;
    }

    public function getHelpType(): ?string
    {
        return $this->helpType;
    }

    public function setHelpType(string $helpType): static
    {
        $this->helpType = $helpType;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

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

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getLang(): ?string
    {
        return $this->lang;
    }

    public function setLang(string $lang): static
    {
        $this->lang = $lang;

        return $this;
    }

    public function getSource(): ContactSource
    {
        return $this->source;
    }

    public function setSource(ContactSource $source): static
    {
        $this->source = $source;

        return $this;
    }
}
