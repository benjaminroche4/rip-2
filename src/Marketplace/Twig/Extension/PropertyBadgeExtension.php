<?php

namespace App\Marketplace\Twig\Extension;

use App\Marketplace\Domain\Property;
use App\Marketplace\Domain\PropertyBadgeType;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Resolves which single badge a property card should display, so the rule
 * ("`new` wins over the category, only the first category drives the badge")
 * lives in one place instead of being duplicated across card templates.
 */
class PropertyBadgeExtension extends AbstractExtension
{
    /** A property is flagged "new" for this long after creation. */
    private const NEW_WINDOW = '-3 days';

    public function getFunctions(): array
    {
        return [
            new TwigFunction('property_badge', $this->resolve(...)),
        ];
    }

    /**
     * The single badge to show. `type` is the resolved badge type value
     * (null for an unrecognized category), `label` the raw category name used
     * as a neutral fallback when the category maps to no known type.
     *
     * @return array{type: ?string, label: ?string}
     */
    public function resolve(Property $property): array
    {
        if ($this->isNew($property)) {
            return ['type' => PropertyBadgeType::New->value, 'label' => null];
        }

        if ($property->showCategoryOnCard && $property->categoryName) {
            $slug = $property->categoryList[0]['slug'] ?? null;

            return [
                'type' => PropertyBadgeType::fromSlug($slug)?->value,
                'label' => $property->categoryName,
            ];
        }

        return ['type' => null, 'label' => null];
    }

    private function isNew(Property $property): bool
    {
        return $property->createdAt !== null
            && $property->createdAt > new \DateTimeImmutable(self::NEW_WINDOW);
    }
}
