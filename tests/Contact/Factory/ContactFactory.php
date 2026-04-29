<?php

namespace App\Tests\Contact\Factory;

use App\Contact\Entity\Contact;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Contact>
 */
final class ContactFactory extends PersistentProxyObjectFactory
{
    public const HELP_TYPES = ['Question', 'Suggestion', 'Other'];

    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return Contact::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
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
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime('-6 month')),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this;
    }
}
