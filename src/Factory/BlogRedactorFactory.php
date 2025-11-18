<?php

namespace App\Factory;

use App\Entity\BlogRedactor;
use App\Repository\BlogRedactorRepository;
use Doctrine\ORM\EntityRepository;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;
use Zenstruck\Foundry\Persistence\Proxy;
use Zenstruck\Foundry\Persistence\ProxyRepositoryDecorator;

/**
 * @extends PersistentProxyObjectFactory<BlogRedactor>
 */
final class BlogRedactorFactory extends PersistentProxyObjectFactory{
    const BIO_FR = "Bienvenue sur notre blog dédié à la vie à Paris. Nous partageons des conseils pratiques.";

    const BIO_EN = "Welcome to our blog dedicated to life in Paris. We share practical advice, insights and real stories to help you settle in.";
    const PHOTO_NAMES = [
        'valerie.webp',
        'olivier.webp',
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
        return BlogRedactor::class;
    }

        /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     *
     * @todo add your default values here
     */
    #[\Override]    protected function defaults(): array|callable    {
        return [
            'fullName' => self::faker()->firstName() . ' ' . self::faker()->lastName(),
            'bioFr' => self::BIO_FR,
            'bioEn' => self::BIO_EN,
            'photo' => self::faker()->randomElement(self::PHOTO_NAMES),
        ];
    }

        /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(BlogRedactor $blogRedactor): void {})
        ;
    }
}
