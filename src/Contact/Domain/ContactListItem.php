<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Read model for a contact form submission shown in the admin list.
 * Keeps Doctrine entities out of templates.
 */
final readonly class ContactListItem
{
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phoneNumber,
        public ?string $company,
        public string $helpType,
        public ?string $message,
        public \DateTimeImmutable $createdAt,
        public string $lang,
        public string $reference,
        public ContactStatus $status,
        public ?string $statusChangedBy,
        public ?string $statusChangedByAvatar,
        public ?string $ip = null,
        public ?\DateTimeImmutable $statusChangedAt = null,
        public ?\DateTimeImmutable $firstTreatedAt = null,
        public ?int $leadRating = null,
        public ?string $leadNote = null,
        public ?string $offer = null,
        public ?RecontactChannel $recontactChannel = null,
        public ?\DateTimeImmutable $recallAt = null,
        public ?ClosureReason $closureReason = null,
        public ?NextStep $nextStep = null,
        public ?LeadSource $leadSource = null,
        public ContactSource $source = ContactSource::Form,
        public ?int $projectBudget = null,
        public ?string $projectAreas = null,
        public ?\DateTimeImmutable $projectMoveInAt = null,
        public ?string $projectPropertyType = null,
        public ?StayDuration $projectStayDuration = null,
        public ?string $projectFurnishing = null,
        public ?GuarantorType $projectGuarantorType = null,
        public ?string $projectNote = null,
        public ?int $assigneeId = null,
        public ?string $assigneeName = null,
        public ?string $assigneeAvatar = null,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
