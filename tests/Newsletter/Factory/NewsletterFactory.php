<?php

namespace App\Tests\Newsletter\Factory;

use App\Newsletter\Entity\Newsletter;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Newsletter>
 */
final class NewsletterFactory extends PersistentObjectFactory
{
    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return Newsletter::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime('-6 month')),
            'email' => self::faker()->email(),
            'subscribe' => self::faker()->boolean(),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this;
    }
}
