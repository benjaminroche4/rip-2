<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use App\Admin\Entity\Document;

/**
 * Read model of a catalogue Document for templates (request form checkboxes,
 * request PDF): the localised name/description are resolved once at mapping
 * time, so the template never touches the Doctrine entity nor a locale
 * switch.
 */
final readonly class DocumentView
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public ?DocumentCategory $category,
        public bool $pinned,
    ) {
    }

    public static function fromEntity(Document $document, string $locale): self
    {
        return new self(
            id: (int) $document->getId(),
            name: $document->getName($locale),
            description: $document->getDescription($locale),
            category: $document->getCategory(),
            pinned: $document->isPinned(),
        );
    }
}
