<?php

declare(strict_types=1);

namespace App\PropertyListing\Service;

/**
 * Lazy attachment descriptor: name/type/size are known without downloading;
 * bytes() fetches the content on demand, so the email handler can skip an
 * over-budget photo without ever rapatriating it.
 */
final readonly class ListingPhoto
{
    /**
     * @param \Closure(): string $reader
     */
    public function __construct(
        public string $name,
        public string $contentType,
        public int $size,
        private \Closure $reader,
    ) {
    }

    public function bytes(): string
    {
        return ($this->reader)();
    }
}
