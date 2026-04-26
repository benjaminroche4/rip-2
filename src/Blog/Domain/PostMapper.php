<?php

namespace App\Blog\Domain;

/**
 * Builds {@see Post} instances from raw GROQ result arrays.
 *
 * Mirrors the structure of Marketplace\Domain\PropertyMapper.
 */
final class PostMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public function fromGroqArray(array $row): Post
    {
        return new Post(
            title: $this->toNullableString($row['title'] ?? null),
            shortDescription: $this->toNullableString($row['shortDescription'] ?? null),
            metaDescription: $this->toNullableString($row['metaDescription'] ?? null),
            slug: $this->toNullableString($row['slug'] ?? null),
            mainPhoto: $this->toNullableString($row['mainPhoto'] ?? null),
            mainPhotoAlt: $this->toNullableString($row['mainPhotoAlt'] ?? null),
            readTime: $this->toNullableInt($row['readTime'] ?? null),
            body: $this->toNullableArray($row['body'] ?? null),
            createdAt: $this->toDateTime($row['_createdAt'] ?? null),
            publishedAt: $this->toDateTime($row['publishedAt'] ?? null),
            category: $this->toNullableArray($row['category'] ?? null),
            authors: $this->toNullableArray($row['authors'] ?? null),
            tags: $this->toNullableArray($row['tags'] ?? null),
            alternateSlug: $this->toNullableArray($row['alternateSlug'] ?? null),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, Post>
     */
    public function fromGroqArrayList(array $rows): array
    {
        return array_values(array_map($this->fromGroqArray(...), $rows));
    }

    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function toNullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_scalar($value) ? (string) $value : null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * @return array<mixed>|null
     */
    private function toNullableArray(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }
}
