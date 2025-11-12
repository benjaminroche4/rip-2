<?php

namespace App\Factory;

use App\Entity\BlogCategory;
use App\Repository\BlogCategoryRepository;
use Doctrine\ORM\EntityRepository;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;
use Zenstruck\Foundry\Persistence\Proxy;
use Zenstruck\Foundry\Persistence\ProxyRepositoryDecorator;

/**
 * @extends PersistentProxyObjectFactory<BlogCategory>
 */
final class BlogCategoryFactory extends PersistentProxyObjectFactory {
    const TITLE_FR = [
        'Vie à Paris',
        'Logement',
        'Relocation',
        'Fiscalité et démarches',
        'Culture et lifestyle',
        'Travail et carrière',
        'Famille et éducation',
        'Mobilité internationale',
        'Conseils pratiques',
        'Actualités'
    ];

    const TITLE_EN = [
        'Life in Paris',
        'Housing',
        'Relocation',
        'Taxes and paperwork',
        'Culture and lifestyle',
        'Work and career',
        'Family and education',
        'International mobility',
        'Practical tips',
        'News'
    ];

    const SLUG_FR = [
        'vie-a-paris',
        'logement',
        'relocation',
        'fiscalite-et-demarches',
        'culture-et-lifestyle',
        'travail-et-carriere',
        'famille-et-education',
        'mobilite-internationale',
        'conseils-pratiques',
        'actualites'
    ];

    const SLUG_EN = [
        'life-in-paris',
        'housing',
        'relocation',
        'taxes-and-paperwork',
        'culture-and-lifestyle',
        'work-and-career',
        'family-and-education',
        'international-mobility',
        'practical-tips',
        'news'
    ];

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#factories-as-services
     *
     * @todo inject services if required
     */
    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return BlogCategory::class;
    }

        /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     *
     * @todo add your default values here
     */
    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'slugEn' => self::faker()->randomElement(self::SLUG_EN),
            'slugFr' => self::faker()->randomElement(self::SLUG_FR),
            'titleEn' => self::faker()->randomElement(self::TITLE_EN),
            'titleFr' => self::faker()->randomElement(self::TITLE_FR),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime('-6 month')),
        ];
    }

        /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(BlogCategory $blogCategory): void {})
        ;
    }
}
