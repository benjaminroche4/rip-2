<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * One row of the global search palette: enough to render a link with a
 * label, a secondary line and an optional status chip. Route params never
 * include the admin prefix, the template injects it.
 */
final readonly class SearchHit
{
    /**
     * @param array<string, string|int> $routeParams
     */
    public function __construct(
        public string $title,
        public ?string $subtitle,
        public string $route,
        public array $routeParams,
        /** i18n key of the status chip; null renders no chip. */
        public ?string $badgeKey = null,
    ) {
    }
}
