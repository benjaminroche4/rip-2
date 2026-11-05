<?php

namespace App\CaseStudy\Repository;

use App\CaseStudy\Domain\CaseStudy;

interface CaseStudyRepositoryInterface
{
    /**
     * Published case studies of a locale, most recent first.
     *
     * @return list<CaseStudy>
     */
    public function findAll(string $locale): array;

    public function findOneBySlug(string $slug, string $locale): ?CaseStudy;
}
