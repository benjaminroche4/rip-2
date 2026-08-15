<?php

declare(strict_types=1);

namespace App\Dossier\Domain;

use App\Dossier\Entity\DossierPerson;

/**
 * Highest advisable monthly rent given the tenants' net incomes (the
 * landlord's "3x rule").
 */
final class AffordableRent
{
    private function __construct()
    {
    }

    /**
     * Null while no tenant income is known.
     *
     * @param iterable<DossierPerson> $persons
     */
    public static function maxBudget(iterable $persons): ?int
    {
        $income = 0;
        foreach ($persons as $person) {
            if (DossierPersonRole::TENANT === $person->getRole()) {
                $income += $person->getMonthlyIncome() ?? 0;
            }
        }

        return $income > 0 ? intdiv($income, 3) : null;
    }
}
