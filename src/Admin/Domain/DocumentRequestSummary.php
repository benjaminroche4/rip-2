<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * Read model for the recent-requests table on the documents hub. Lets the
 * template render rows without touching the underlying Doctrine entities,
 * keeping the lazy-loaded `persons` collection out of the rendering path.
 */
final readonly class DocumentRequestSummary
{
    /**
     * @param list<string> $personNames Full names ("Firstname Lastname") in
     *                                   display (position) order.
     */
    public function __construct(
        public int $id,
        public \DateTimeImmutable $createdAt,
        public HouseholdTypology $typology,
        public RequestLanguage $language,
        public array $personNames,
    ) {
    }

    public function personCount(): int
    {
        return \count($this->personNames);
    }
}
