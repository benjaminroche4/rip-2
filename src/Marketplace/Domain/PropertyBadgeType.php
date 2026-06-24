<?php

namespace App\Marketplace\Domain;

/**
 * The single promotional badge shown on a property card.
 *
 * The backed value matches both the i18n key suffix
 * (`marketplace.list.card.category.<value>`) and the style map in
 * templates/components/Marketplace/PropertyBadge.html.twig.
 */
enum PropertyBadgeType: string
{
    case New = 'new';
    case Featured = 'featured';
    case Exclusive = 'exclusive';
    case Renovated = 'renovated';
    case Premium = 'premium';
    case ReadyToMove = 'readyToMove';

    /**
     * Resolve a Sanity category slug (FR or EN form) to a badge type,
     * or null when the slug maps to no known type.
     */
    public static function fromSlug(?string $slug): ?self
    {
        return match ($slug) {
            'new', 'nouveau' => self::New,
            'featured', 'coup-de-coeur' => self::Featured,
            'exclusive', 'exclusivite', 'exclusivity' => self::Exclusive,
            'renovated', 'renove', 'renovation' => self::Renovated,
            'premium' => self::Premium,
            'ready-to-move', 'disponible-de-suite', 'disponible' => self::ReadyToMove,
            default => null,
        };
    }
}
