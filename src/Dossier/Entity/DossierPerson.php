<?php

declare(strict_types=1);

namespace App\Dossier\Entity;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
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
