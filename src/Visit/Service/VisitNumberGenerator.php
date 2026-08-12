<?php

declare(strict_types=1);

namespace App\Visit\Service;

use App\Visit\Repository\VisitRepository;

/**
 * Random public identifier for visits ("VS-087526"). Uniqueness is checked
 * against the DB before returning; the unique index remains the final
 * guard against the (negligible) race between check and flush.
 */
final class VisitNumberGenerator
{
    public function __construct(
        private readonly VisitRepository $repository,
    ) {
    }

    public function reference(): string
    {
        do {
            $reference = \sprintf('VS-%06d', random_int(0, 999999));
        } while (null !== $this->repository->findOneBy(['reference' => $reference]));

        return $reference;
    }
}
