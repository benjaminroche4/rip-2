<?php

declare(strict_types=1);

namespace App\Marketing\Service;

use App\Marketing\Domain\FaqCategory;
use App\Marketing\Domain\FaqEntry;

/**
 * The FAQ page, by topic. Questions reuse the answers already validated on the
 * service pages (home, find accommodation, companies, property management,
 * find a tenant, pricing): one wording per answer across the whole site.
 */
final class FaqCatalog
{
    /**
     * Topic slug => [title key, question prefixes (`<prefix>.title` / `<prefix>.text`)].
     * Answers link to other pages in their own copy (internal_link_params()).
     */
    private const CATEGORIES = [
        'relocation' => ['faq.categories.relocation', [
            'home.faq.question.1',
            'faq.6',
            'home.faq.question.5',
            'faq.3',
            'faq.more.beyondHousing',
            'faq.more.languages',
            'faq.more.contact',
        ]],
        'home-search' => ['faq.categories.homeSearch', [
            'faq.7',
            'faq.2',
            'home.faq.question.8',
            'faq.8',
            'faq.11',
            'faq.12',
            'faq.13',
            'faq.4',
            'faq.1',
            'faq.more.whenToStart',
            'faq.more.temporaryHousing',
            'faq.more.ourProperties',
        ]],
        'renting-rules' => ['faq.categories.rentingRules', [
            'faq.more.guarantor',
            'faq.more.furnishedVsUnfurnished',
            'faq.more.mobilityLease',
            'faq.more.rentControl',
            'faq.more.moveInCosts',
        ]],
        'application' => ['faq.categories.application', [
            'faq.10',
            'findAccommodation.faq.question.6',
            'faq.14',
            'home.faq.question.9',
        ]],
        'plans-and-fees' => ['faq.categories.plansAndFees', [
            'faq.more.prices',
            'faq.plans.difference',
            'faq.9',
            'faq.5',
        ]],
        'companies' => ['faq.categories.companies', [
            'companies.faq.question.1',
            'companies.faq.question.2',
            'companies.faq.question.3',
            'companies.faq.question.4',
            'companies.faq.question.5',
            'companies.faq.question.6',
        ]],
        'property-management' => ['faq.categories.propertyManagement', [
            'propertyManagement.faq.question.1',
            'propertyManagement.faq.question.2',
            'propertyManagement.faq.question.3',
            'propertyManagement.faq.question.4',
            'propertyManagement.faq.question.5',
            'propertyManagement.faq.question.6',
            'propertyManagement.faq.question.7',
            'propertyManagement.faq.question.8',
            'faq.more.rentEstimate',
        ]],
    ];

    /** Topic slug => note key, shown as a card above the topic's questions. */
    private const NOTES = [
        'property-management' => 'faq.notes.propertyManagement',
    ];

    /**
     * @return list<FaqCategory>
     */
    public function categories(): array
    {
        $categories = [];
        foreach (self::CATEGORIES as $slug => [$titleKey, $prefixes]) {
            $categories[] = new FaqCategory(
                slug: $slug,
                titleKey: $titleKey,
                entries: array_map(FaqEntry::fromPrefix(...), $prefixes),
                noteKey: self::NOTES[$slug] ?? null,
            );
        }

        return $categories;
    }
}
