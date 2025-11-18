<?php

namespace App\Entity;

use App\Repository\BlogRedactorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlogRedactorRepository::class)]
class BlogRedactor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private ?string $fullName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bioFr = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bioEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    /**
     * @var Collection<int, Blog>
     */
    #[ORM\OneToMany(targetEntity: Blog::class, mappedBy: 'redactor')]
    private Collection $blogs;

    public function __construct()
    {
        $this->blogs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getBioFr(): ?string
    {
        return $this->bioFr;
    }

    public function setBioFr(?string $bioFr): static
    {
        $this->bioFr = $bioFr;

        return $this;
    }

    public function getBioEn(): ?string
    {
        return $this->bioEn;
    }

    public function setBioEn(?string $bioEn): static
    {
        $this->bioEn = $bioEn;

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    /**
     * @return Collection<int, Blog>
     */
    public function getBlogs(): Collection
    {
        return $this->blogs;
    }

    public function addBlog(Blog $blog): static
    {
        if (!$this->blogs->contains($blog)) {
            $this->blogs->add($blog);
            $blog->setRedactor($this);
        }

        return $this;
    }

    public function removeBlog(Blog $blog): static
    {
        if ($this->blogs->removeElement($blog)) {
            // set the owning side to null (unless already changed)
            if ($blog->getRedactor() === $this) {
                $blog->setRedactor(null);
            }
        }

        return $this;
    }

    public function __toString()
    {
        return $this->fullName;
    }
}
