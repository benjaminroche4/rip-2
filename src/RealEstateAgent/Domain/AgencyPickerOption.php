<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * One row of the agency picker on the "new agent" form: just enough to
 * render the option (logo, name, address) and post the selection back.
 */
final readonly class AgencyPickerOption
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $logoFilename,
        public ?string $address,
        public ?string $brand = null,
    ) {
    }
}
