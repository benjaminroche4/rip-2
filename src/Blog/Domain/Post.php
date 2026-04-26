<?php

namespace App\Blog\Domain;

/**
 * Typed snapshot of a Sanity blog document.
 *
 * Sub-objects (category, authors, alternateSlug) stay as arrays for now —
 * promoting them to nested DTOs is a follow-up if their schema stabilizes.
 *
 * Field names mirror the LIST_FIELDS / detail GROQ projections so templates
 * keep using the same dotted accessors. The legacy `post._createdAt` lookup
 * (raw Sanity field name with leading underscore) is served by get_createdAt().
 */
final readonly class Post
{
    public function __construct(
        public ?string $title = null,
        public ?string $shortDescription = null,
        public ?string $metaDescription = null,
        public ?string $slug = null,
        /** Sanity image asset URL (built via mainPhoto.asset->url in GROQ). */
        public ?string $mainPhoto = null,
        public ?string $mainPhotoAlt = null,
        public ?int $readTime = null,
        /** @var array<int, array<string, mixed>>|null Portable Text blocks. */
        public ?array $body = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $publishedAt = null,
        /** @var array{name?: string, slug?: string, color?: string}|null */
        public ?array $category = null,
        /** @var array<int, array{fullName?: string, photo?: string}>|null */
        public ?array $authors = null,
        /** @var array<int, string>|null */
        public ?array $tags = null,
        /** @var array{_key?: string, slug?: string}|null */
        public ?array $alternateSlug = null,
    ) {
    }

    /**
     * Twig back-compat: templates that read `post._createdAt` (the raw
     * Sanity field name) resolve to this method.
     */
    public function get_createdAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
