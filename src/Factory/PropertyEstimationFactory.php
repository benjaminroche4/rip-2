<?php

namespace App\Factory;

use App\Entity\PropertyEstimation;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<PropertyEstimation>
 */
final class PropertyEstimationFactory extends PersistentObjectFactory
{
    const PROPERTY_CONDITION = ['Usé', 'Standard', 'Premium', 'Luxe'];

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
        return PropertyEstimation::class;
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
            'address' => self::faker()->address(),
            'bathroom' => self::faker()->numberBetween(1, 10),
            'bedroom' => self::faker()->numberBetween(1, 10),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime('-6 month')),
            'email' => self::faker()->email(),
            'ip' => self::faker()->ipv6(),
            'phoneNumber' => self::faker()->phoneNumber(),
            'propertyCondition' => self::faker()->randomElement(self::PROPERTY_CONDITION),
            'surface' => self::faker()->numberBetween(100, 300),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]
    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(PropertyEstimation $propertyEstimation): void {})
        ;
    }
}
