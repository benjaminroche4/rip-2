<?php

declare(strict_types=1);

namespace App\Contact\Domain;

/**
 * When the visitor wants to settle in Paris (call-back form).
 */
enum SettleTiming: string
{
    case Asap = 'asap';
    case TwoToFourWeeks = 'two_to_four_weeks';
    case OneToTwoMonths = 'one_to_two_months';
    case OverThreeMonths = 'over_three_months';

    public function labelKey(): string
    {
        return 'callback.form.settleTiming.choice.'.$this->value;
    }
}
