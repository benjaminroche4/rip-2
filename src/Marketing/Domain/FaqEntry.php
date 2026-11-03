<?php

declare(strict_types=1);

namespace App\Marketing\Domain;

/**
 * One FAQ question, as translation keys (answers may hold controlled HTML and
 * internal links through the `internal_link_params()` placeholders).
 */
final readonly class FaqEntry
{
    public function __construct(
        public string $questionKey,
        public string $answerKey,
    ) {
    }

    /** Shorthand for the `<prefix>.title` / `<prefix>.text` convention used across the site. */
    public static function fromPrefix(string $prefix): self
    {
        return new self($prefix.'.title', $prefix.'.text');
    }
}
