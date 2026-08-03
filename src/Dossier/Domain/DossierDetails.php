<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

/**
 * Read model for the dossier detail page — templates never see the Doctrine
 * entities, only this view.
 */
final readonly class DossierDetails
{
    /**
     * @param list<DossierPersonView> $persons
     * @param list<DossierNoteView>   $notes
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $reference,
        public string $pairingCode,
        public \DateTimeImmutable $createdAt,
        public array $persons,
        public ?DossierSearchView $search,
        public array $notes,
        public ?string $sourceContactReference,
    ) {
    }
}
