<?php

declare(strict_types=1);

namespace App\Visit\Domain;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated payload of the visit report POST (admin_visit_report). The
 * report is optional free text (empty clears it), the feeling an optional
 * enum value (empty clears it too).
 */
final readonly class VisitReportInput
{
    public function __construct(
        #[Assert\Length(max: 5000, maxMessage: 'The report cannot exceed {{ limit }} characters.')]
        public string $report,
        #[Assert\Choice(callback: [self::class, 'feelings'], message: 'Unknown client feeling.')]
        public ?string $feeling,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function feelings(): array
    {
        return array_column(ClientFeeling::cases(), 'value');
    }

    public function reportOrNull(): ?string
    {
        return '' !== $this->report ? $this->report : null;
    }

    public function toFeeling(): ?ClientFeeling
    {
        return null !== $this->feeling ? ClientFeeling::from($this->feeling) : null;
    }
}
