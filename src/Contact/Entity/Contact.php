<?php

namespace App\Contact\Entity;

use App\Contact\Domain\ContactMethod;
use App\Contact\Domain\ContactSlot;
use App\Contact\Domain\Furnishing;
use App\Contact\Domain\Guarantor;
use App\Contact\Domain\RentBudget;
use App\Contact\Domain\Rooms;
use App\Contact\Domain\SettleTiming;
use App\Contact\Domain\Situation;
use App\Contact\Repository\ContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContactRepository::class)]
class Contact
{
    /** Toutes les formules connues, seule source de vérité du champ offer. */
    public const OFFERS = ['accompagne', 'confie'];

    /** Which public form produced the row (filled by the form path, never edited afterwards). */
    public const SOURCE_CONTACT = 'contact';
    public const SOURCE_CALLBACK = 'callback';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Public reference ("CT-082820") usable in email exchanges. Random rather
     * than the auto-increment id so the submission volume isn't guessable.
     */
    #[ORM\Column(length: 9, unique: true)]
    private string $reference;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 254, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(length: 100)]
    private string $helpType;

    /**
     * Package chosen on the public form ("accompagne" or "confie"), only
     * set when the help type is the housing search.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $offer = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 5)]
    private string $lang;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $source = null;

    // Call-back form ("On vous rappelle") answers, null for the contact form

    #[ORM\Column(length: 20, nullable: true, enumType: Situation::class)]
    private ?Situation $situation = null;

    #[ORM\Column(length: 20, nullable: true, enumType: SettleTiming::class)]
    private ?SettleTiming $settleTiming = null;

    #[ORM\Column(length: 20, nullable: true, enumType: Furnishing::class)]
    private ?Furnishing $furnishing = null;

    #[ORM\Column(length: 20, nullable: true, enumType: Rooms::class)]
    private ?Rooms $rooms = null;

    #[ORM\Column(length: 20, nullable: true, enumType: Guarantor::class)]
    private ?Guarantor $guarantor = null;

    #[ORM\Column(length: 20, nullable: true, enumType: RentBudget::class)]
    private ?RentBudget $rentBudget = null;

    #[ORM\Column(length: 20, nullable: true, enumType: ContactMethod::class)]
    private ?ContactMethod $contactMethod = null;

    #[ORM\Column(length: 20, nullable: true, enumType: ContactSlot::class)]
    private ?ContactSlot $contactSlot = null;

    public function __construct()
    {
        $this->reference = 'CT-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
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

    public function setEmail(?string $email): static
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
        $this->company = $company;

        return $this;
    }

    public function getHelpType(): string
    {
        return $this->helpType;
    }

    public function setHelpType(string $helpType): static
    {
        $this->helpType = $helpType;

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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
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

    public function getLang(): string
    {
        return $this->lang;
    }

    public function setLang(string $lang): static
    {
        $this->lang = $lang;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getSituation(): ?Situation
    {
        return $this->situation;
    }

    public function setSituation(?Situation $situation): static
    {
        $this->situation = $situation;

        return $this;
    }

    public function getSettleTiming(): ?SettleTiming
    {
        return $this->settleTiming;
    }

    public function setSettleTiming(?SettleTiming $settleTiming): static
    {
        $this->settleTiming = $settleTiming;

        return $this;
    }

    public function getFurnishing(): ?Furnishing
    {
        return $this->furnishing;
    }

    public function setFurnishing(?Furnishing $furnishing): static
    {
        $this->furnishing = $furnishing;

        return $this;
    }

    public function getRooms(): ?Rooms
    {
        return $this->rooms;
    }

    public function setRooms(?Rooms $rooms): static
    {
        $this->rooms = $rooms;

        return $this;
    }

    public function getGuarantor(): ?Guarantor
    {
        return $this->guarantor;
    }

    public function setGuarantor(?Guarantor $guarantor): static
    {
        $this->guarantor = $guarantor;

        return $this;
    }

    public function getRentBudget(): ?RentBudget
    {
        return $this->rentBudget;
    }

    public function setRentBudget(?RentBudget $rentBudget): static
    {
        $this->rentBudget = $rentBudget;

        return $this;
    }

    public function getContactMethod(): ?ContactMethod
    {
        return $this->contactMethod;
    }

    public function setContactMethod(?ContactMethod $contactMethod): static
    {
        $this->contactMethod = $contactMethod;

        return $this;
    }

    public function getContactSlot(): ?ContactSlot
    {
        return $this->contactSlot;
    }

    public function setContactSlot(?ContactSlot $contactSlot): static
    {
        $this->contactSlot = $contactSlot;

        return $this;
    }
}
