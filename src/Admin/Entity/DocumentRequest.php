<?php

declare(strict_types=1);

namespace App\Admin\Entity;

use App\Admin\Domain\HouseholdTypology;
use App\Admin\Domain\RequestLanguage;
use App\Admin\Repository\DocumentRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DocumentRequestRepository::class)]
#[ORM\Table(name: 'document_request')]
class DocumentRequest
{
    public const MAX_PERSONS = 4;
    public const MIN_PERSONS = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, enumType: HouseholdTypology::class)]
    #[Assert\NotNull(message: 'admin.tools.documents.request.typology.notNull')]
    private ?HouseholdTypology $typology = null;

    #[ORM\Column(length: 512)]
    #[Assert\NotBlank(message: 'admin.tools.documents.request.driveLink.notBlank')]
    #[Assert\Url(message: 'admin.tools.documents.request.driveLink.url', requireTld: true)]
    #[Assert\Length(max: 512, maxMessage: 'admin.tools.documents.request.driveLink.length')]
    private ?string $driveLink = null;

    #[ORM\Column(length: 2, enumType: RequestLanguage::class)]
    #[Assert\NotNull]
    private RequestLanguage $language = RequestLanguage::FR;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: 'admin.tools.documents.request.note.length')]
    private ?string $note = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Ordered list of persons covered by the request. Bound to the
     * CollectionType in DocumentRequestType, hence allow_add/remove via
     * the LiveCollectionTrait.
     *
     * @var Collection<int, PersonRequest>
     */
    #[ORM\OneToMany(
        targetEntity: PersonRequest::class,
        mappedBy: 'documentRequest',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Count(
        min: self::MIN_PERSONS,
        max: self::MAX_PERSONS,
        minMessage: 'admin.tools.documents.request.persons.min',
        maxMessage: 'admin.tools.documents.request.persons.max',
    )]
    #[Assert\Valid]
    private Collection $persons;

    public function __construct()
    {
        $this->persons = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTypology(): ?HouseholdTypology
    {
        return $this->typology;
    }

    public function setTypology(?HouseholdTypology $typology): static
    {
        $this->typology = $typology;

        return $this;
    }

    public function getDriveLink(): ?string
    {
        return $this->driveLink;
    }

    public function setDriveLink(?string $driveLink): static
    {
        $this->driveLink = $driveLink;

        return $this;
    }

    public function getLanguage(): RequestLanguage
    {
        return $this->language;
    }

    public function setLanguage(RequestLanguage $language): static
    {
        $this->language = $language;

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
     * @return Collection<int, PersonRequest>
     */
    public function getPersons(): Collection
    {
        return $this->persons;
    }

    public function addPerson(PersonRequest $person): static
    {
        if (!$this->persons->contains($person)) {
            $person->setDocumentRequest($this);
            $person->setPosition($this->persons->count());
            $this->persons->add($person);
        }

        return $this;
    }

    public function removePerson(PersonRequest $person): static
    {
        if ($this->persons->removeElement($person)) {
            // unset the owning side only if still pointing to this
            if ($person->getDocumentRequest() === $this) {
                $person->setDocumentRequest(null);
            }
        }

        return $this;
    }
}
