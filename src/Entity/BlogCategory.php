<?php

namespace App\Entity;

use App\Repository\BlogCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlogCategoryRepository::class)]
class BlogCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private ?string $slugFr = null;

    #[ORM\Column(length: 120)]
    private ?string $slugEn = null;

    #[ORM\Column(length: 120)]
    private ?string $titleFr = null;

    #[ORM\Column(length: 120)]
    private ?string $titleEn = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, Blog>
     */
    #[ORM\ManyToMany(targetEntity: Blog::class, mappedBy: 'category')]
    private Collection $blogs;

    public function __construct()
    {
        $this->blogs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlugFr(): ?string
    {
        return $this->slugFr;
    }

    public function setSlugFr(string $slugFr): static
    {
        $this->slugFr = $slugFr;

        return $this;
    }

    public function getSlugEn(): ?string
    {
        return $this->slugEn;
    }

    public function setSlugEn(string $slugEn): static
    {
        $this->slugEn = $slugEn;

        return $this;
    }

    public function getTitleFr(): ?string
    {
        return $this->titleFr;
    }

    public function setTitleFr(string $titleFr): static
    {
        $this->titleFr = $titleFr;

        return $this;
    }

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function setTitleEn(string $titleEn): static
    {
        $this->titleEn = $titleEn;

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
            $blog->addCategory($this);
        }

        return $this;
    }

    public function removeBlog(Blog $blog): static
    {
        if ($this->blogs->removeElement($blog)) {
            $blog->removeCategory($this);
        }

        return $this;
    }
}
