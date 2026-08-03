<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Read model for one entry of the dossier's follow-up thread.
 */
final readonly class DossierNoteView
{
    public function __construct(
        public string $text,
        public \DateTimeImmutable $createdAt,
        public string $authorName,
        public ?string $authorAvatar,
    ) {
    }
}
