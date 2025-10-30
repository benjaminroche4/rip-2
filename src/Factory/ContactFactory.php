<?php

namespace App\Factory;

use App\Entity\Contact;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityRepository;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;
use Zenstruck\Foundry\Persistence\Proxy;
use Zenstruck\Foundry\Persistence\ProxyRepositoryDecorator;

/**
 * @extends PersistentProxyObjectFactory<Contact>
 */
final class ContactFactory extends PersistentProxyObjectFactory{
    const HELP_TYPES = ['Question', 'Suggestion', 'Other'];
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
        return Contact::class;
    }

        /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     *
     * @todo add your default values here
     */
    #[\Override]    protected function defaults(): array|callable    {
        return [
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'email' => self::faker()->email(),
            'phoneNumber' => self::faker()->phoneNumber(),
            'company' => self::faker()->company(),
            'helpType' => self::faker()->randomElement(self::HELP_TYPES),
            'message' => self::faker()->text(1000),
            'lang' => self::faker()->languageCode(),
            'ip' => self::faker()->ipv4(),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
        ];
    }

        /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(Contact $contact): void {})
        ;
    }
}
