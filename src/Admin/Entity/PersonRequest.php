<?php

declare(strict_types=1);

namespace App\Admin\Entity;

use App\Admin\Domain\PersonRole;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'person_request')]
class PersonRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: PersonRole::class)]
    #[Assert\NotNull(message: 'admin.tools.documents.request.person.role.notNull')]
    private ?PersonRole $role = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'admin.tools.documents.request.person.firstName.notBlank')]
    #[Assert\Length(min: 2, max: 50, minMessage: 'admin.tools.documents.request.person.firstName.length', maxMessage: 'admin.tools.documents.request.person.firstName.length')]
    private ?string $firstName = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'admin.tools.documents.request.person.lastName.notBlank')]
    #[Assert\Length(min: 2, max: 50, minMessage: 'admin.tools.documents.request.person.lastName.length', maxMessage: 'admin.tools.documents.request.person.lastName.length')]
    private ?string $lastName = null;

    /**
     * Display order within the request, set when the person is appended via
     * DocumentRequest::addPerson(). Lets us render the cards in insertion order
     * even after persistence reshuffles the collection.
     */
    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: DocumentRequest::class, inversedBy: 'persons')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DocumentRequest $documentRequest = null;

    /**
     * Documents (from the catalogue) the admin wants this person to upload.
     *
     * @var Collection<int, Document>
     */
    #[ORM\ManyToMany(targetEntity: Document::class)]
    #[ORM\JoinTable(name: 'person_request_document')]
    #[Assert\Count(
        min: 1,
        minMessage: 'admin.tools.documents.request.person.documents.min',
    )]
    private Collection $documents;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        // role is left null on purpose so the segmented pill in the sidebar
        // renders with neither option selected. The admin has to explicitly
        // pick "Locataire" or "Garant"; otherwise NotNull validation kicks
        // in at submit time.
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): ?PersonRole
    {
        return $this->role;
    }

    public function setRole(?PersonRole $role): static
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getDocumentRequest(): ?DocumentRequest
    {
        return $this->documentRequest;
    }

    public function setDocumentRequest(?DocumentRequest $documentRequest): static
    {
        $this->documentRequest = $documentRequest;

        return $this;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        $this->documents->removeElement($document);

        return $this;
    }
}
