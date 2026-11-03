<?php

declare(strict_types=1);

namespace App\Blog\Domain;

/**
 * Typed snapshot of a Sanity `author` document, scoped to one locale
 * (postCount only counts the published posts of that locale).
 */
final readonly class Author
{
    public function __construct(
        public string $fullName,
        public string $slug,
        /** Sanity image asset URL (built via photo.asset->url in GROQ). */
        public ?string $photo = null,
        public int $postCount = 0,
        /** Job title in the requested locale (Sanity `role[$locale]`). */
        public ?string $role = null,
        /** Short first-person bio in the requested locale (Sanity `description[$locale]`). */
        public ?string $description = null,
        /** Creation date of the author's latest post in this locale (sitemap lastmod). */
        public ?\DateTimeImmutable $lastPostAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromGroqArray(array $row): ?self
    {
        $fullName = $row['fullName'] ?? null;
        $slug = $row['slug'] ?? null;
        if (!is_string($fullName) || '' === $fullName || !is_string($slug) || '' === $slug) {
            return null;
        }

        $photo = $row['photo'] ?? null;
        $role = $row['role'] ?? null;
        $description = $row['description'] ?? null;
        $postCount = $row['postCount'] ?? 0;
        $lastPost = $row['lastPostAt'] ?? null;
        try {
            $lastPostAt = is_string($lastPost) && '' !== $lastPost ? new \DateTimeImmutable($lastPost) : null;
        } catch (\Exception) {
            $lastPostAt = null;
        }

        return new self(
            fullName: $fullName,
            slug: $slug,
            photo: is_string($photo) && '' !== $photo ? $photo : null,
            postCount: is_int($postCount) ? $postCount : 0,
            role: is_string($role) && '' !== trim($role) ? trim($role) : null,
            description: is_string($description) && '' !== trim($description) ? trim($description) : null,
            lastPostAt: $lastPostAt,
        );
    }
}
