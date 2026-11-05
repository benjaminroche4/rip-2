<?php

namespace App\CaseStudy\Domain;

/**
 * Parts of the tenant file we put together for a client ("We build for you").
 * A fixed vocabulary: in the CMS it will be a pick list, not free text.
 */
enum CaseStudyDocument: string
{
    case Employer = 'employer';
    case Guarantor = 'guarantor';
    case International = 'international';
    case Income = 'income';
    case Scholarship = 'scholarship';

    public function labelKey(): string
    {
        return 'caseStudy.document.'.$this->value;
    }

    /** One line on what the part contains, e.g. "Payslips and tax notices". */
    public function descriptionKey(): string
    {
        return 'caseStudy.document.'.$this->value.'Text';
    }

    public function icon(): string
    {
        return match ($this) {
            self::Employer => 'lucide:building-2',
            self::Guarantor => 'lucide:user-check',
            self::International => 'lucide:globe',
            self::Income => 'lucide:wallet',
            self::Scholarship => 'lucide:graduation-cap',
        };
    }
}
