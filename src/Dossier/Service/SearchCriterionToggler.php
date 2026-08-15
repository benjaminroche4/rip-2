<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\CsvSelection;
use App\Dossier\Domain\SearchCriterion;
use App\Dossier\Entity\DossierSearch;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Chip toggle semantics of the search card: validates the clicked value
 * against the criterion's whitelist, then either adds/removes it from the
 * CSV (multi-select) or sets it with toggle-off on the active chip
 * (single-select). Mutates the snapshot only; flushing stays caller-side.
 */
final class SearchCriterionToggler
{
    public function toggle(DossierSearch $search, SearchCriterion $criterion, string|int $value): void
    {
        $raw = (string) $value;
        if (!\in_array($raw, $criterion->allowedValues(), true)) {
            throw new BadRequestHttpException($criterion->invalidMessage($value));
        }

        if ($criterion->isMulti()) {
            $criterion->write($search, CsvSelection::toggle($criterion->current($search), $raw) ?: null);

            return;
        }

        $criterion->write($search, $criterion->current($search) === $raw ? null : $raw);
    }
}
