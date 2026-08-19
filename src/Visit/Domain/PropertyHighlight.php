<?php

declare(strict_types=1);

namespace App\Visit\Domain;

/**
 * Quick positive tags about the visited property ("Les plus du logement"),
 * ticked by the agent in the report block. Positive only, on purpose: the
 * downsides live in the free-text internal report. The enum order is the
 * display order everywhere (chips, dossier synthesis, client email).
 */
enum PropertyHighlight: string
{
    case Bright = 'bright';
    case Quiet = 'quiet';
    case WellLaidOut = 'well_laid_out';
    case Renovated = 'renovated';
    case Spacious = 'spacious';
    case NiceView = 'nice_view';
    case NotOverlooked = 'not_overlooked';
    case HighCeilings = 'high_ceilings';
    case Outdoor = 'outdoor';
    case WellLocated = 'well_located';

    public function labelKey(): string
    {
        return 'admin.visits.highlight.'.$this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Bright => 'lucide:sun',
            self::Quiet => 'lucide:volume-x',
            self::WellLaidOut => 'lucide:layout-grid',
            self::Renovated => 'lucide:sparkles',
            self::Spacious => 'lucide:maximize-2',
            self::NiceView => 'lucide:mountain',
            self::NotOverlooked => 'lucide:eye-off',
            self::HighCeilings => 'lucide:arrow-up-down',
            self::Outdoor => 'lucide:trees',
            self::WellLocated => 'lucide:map-pin',
        };
    }
}
