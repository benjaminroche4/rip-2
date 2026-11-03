<?php

declare(strict_types=1);

namespace App\Marketing\Domain;

/**
 * A FAQ topic: its anchor slug (stable across locales), title key and questions.
 */
final readonly class FaqCategory
{
    /**
     * @param list<FaqEntry> $entries
     */
    public function __construct(
        public string $slug,
        public string $titleKey,
        public array $entries,
        /** Optional highlighted note shown above the questions (translation key). */
        public ?string $noteKey = null,
    ) {
    }
}
