<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * Read model for a follow-up note in the contact detail thread.
 */
final readonly class ContactNoteItem
{
    public function __construct(
        public int $id,
        public string $text,
        public \DateTimeImmutable $createdAt,
        public int $authorId,
        public string $authorName,
        public ?string $authorAvatar,
        /** Id of the note this one answers (null = top-level note). */
        public ?int $parentId = null,
    ) {
    }
}
