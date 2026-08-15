<?php

declare(strict_types=1);

namespace App\Visit\Domain;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated payload of the visit status POST (admin_visit_status): the raw
 * request value never reaches the domain logic without going through the
 * Validator, per the project-wide input rules.
 */
final readonly class VisitStatusInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Unknown visit status.')]
        #[Assert\Choice(callback: [self::class, 'statuses'], message: 'Unknown visit status.')]
        public string $status,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return array_column(VisitStatus::cases(), 'value');
    }

    public function toStatus(): VisitStatus
    {
        return VisitStatus::from($this->status);
    }
}
