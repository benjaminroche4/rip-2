<?php

namespace App\Tests\Auth\Factory;

use App\Auth\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<User>
 */
final class UserFactory extends PersistentProxyObjectFactory
{
    private UserPasswordHasherInterface $passwordHasher;
    public const USER_ROLES = ['ROLE_USER', 'ROLE_ADMIN'];

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        parent::__construct();

        $this->passwordHasher = $passwordHasher;
    }

    #[\Override]
    public static function class(): string
    {
        return User::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'email' => self::faker()->email(),
            'password' => 'admin',
            'roles' => [self::faker()->randomElement(self::USER_ROLES)],
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime('-6 month')),
            'profileComplete' => true,
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this->afterInstantiate(function (User $user) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $user->getPassword()));
        });
    }
}