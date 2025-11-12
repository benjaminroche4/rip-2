<?php

namespace App\Factory;

use App\Entity\Blog;
use App\Repository\BlogRepository;
use Doctrine\ORM\EntityRepository;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;
use Zenstruck\Foundry\Persistence\Proxy;
use Zenstruck\Foundry\Persistence\ProxyRepositoryDecorator;

/**
 * @extends PersistentProxyObjectFactory<Blog>
 */
final class BlogFactory extends PersistentProxyObjectFactory{
    const TITLE_FR = [
        '5 raisons de s’installer à Paris en 2025',
        'Comment trouver un logement rapidement à Paris',
        'Les quartiers les plus agréables pour vivre à Paris',
        'Déménager à Paris : les démarches essentielles à connaître',
        'Coût de la vie à Paris : ce qu’il faut prévoir',
        'Les erreurs à éviter quand on s’installe à Paris',
        'Pourquoi faire appel à un service de relocation',
        'Les avantages de vivre dans Paris intra-muros',
        'Les meilleurs conseils pour réussir son expatriation à Paris',
        'Comment bien préparer son arrivée en France'
    ];

    const TITLE_EN = [
        '5 reasons to move to Paris in 2025',
        'How to find a home quickly in Paris',
        'The most pleasant neighborhoods to live in Paris',
        'Moving to Paris: essential steps to know',
        'Cost of living in Paris: what to expect',
        'Mistakes to avoid when relocating to Paris',
        'Why use a relocation service',
        'The benefits of living within Paris city limits',
        'Top tips for a successful relocation to Paris',
        'How to prepare your arrival in France'
    ];

    const SLUG_FR = [
        '5-raisons-de-sinstaller-a-paris-en-2025',
        'comment-trouver-un-logement-rapidement-a-paris',
        'les-quartiers-les-plus-agreables-pour-vivre-a-paris',
        'demanager-a-paris-les-demarches-essentielles-a-connaitre',
        'cout-de-la-vie-a-paris-ce-quil-faut-prevoir',
        'les-erreurs-a-eviter-quand-on-sinstalle-a-paris',
        'pourquoi-faire-appel-a-un-service-de-relocation',
        'les-avantages-de-vivre-dans-paris-intra-muros',
        'les-meilleurs-conseils-pour-reussir-son-expatriation-a-paris',
        'comment-bien-preparer-son-arrivee-en-france'
    ];

    const SLUG_EN = [
        '5-reasons-to-move-to-paris-in-2025',
        'how-to-find-a-home-quickly-in-paris',
        'the-most-pleasant-neighborhoods-to-live-in-paris',
        'moving-to-paris-essential-steps-to-know',
        'cost-of-living-in-paris-what-to-expect',
        'mistakes-to-avoid-when-relocating-to-paris',
        'why-use-a-relocation-service',
        'the-benefits-of-living-within-paris-city-limits',
        'top-tips-for-a-successful-relocation-to-paris',
        'how-to-prepare-your-arrival-in-france'
    ];

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#factories-as-services
     *
     * @todo inject services if required
     */
    public function __construct()
    {
    }

    #[\Override]    public static function class(): string
    {
        return Blog::class;
    }

        /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     *
     * @todo add your default values here
     */
    #[\Override]    protected function defaults(): array|callable    {
        return [
            'contentFr' => 'FR_' . self::faker()->text(5000),
            'contentEn' => 'EN_' . self::faker()->text(5000),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime('-6 month')),
            'isVisible' => self::faker()->boolean(60),
            'slugEn' => self::faker()->randomElement(self::SLUG_EN),
            'slugFr' => self::faker()->randomElement(self::SLUG_FR),
            'titleEn' => self::faker()->randomElement(self::TITLE_EN),
            'titleFr' => self::faker()->randomElement(self::TITLE_FR),
            'redactor' => BlogRedactorFactory::new(),
            'category' => BlogCategoryFactory::randomRange(1, 3),
        ];
    }

        /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(Blog $blog): void {})
        ;
    }
}
