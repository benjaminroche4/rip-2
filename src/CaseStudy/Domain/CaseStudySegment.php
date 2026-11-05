<?php

namespace App\CaseStudy\Domain;

/**
 * Who the relocation was for. Backs the filter tabs of the listing (?category=private|company).
 */
enum CaseStudySegment: string
{
    case Private = 'private';
    case Company = 'company';

    public function labelKey(): string
    {
        return 'caseStudy.segment.'.$this->value;
    }

    /** Route of the service page this kind of client goes through (internal linking from a case study). */
    public function serviceRoute(): string
    {
        return match ($this) {
            self::Private => 'app_service_find_accommodation',
            self::Company => 'app_service_companies',
        };
    }

    /** Dot color of the segment badge, same role as a blog category color. */
    public function color(): string
    {
        return match ($this) {
            self::Private => '#EB9CB0',
            self::Company => '#7FA6E0',
        };
    }
}
