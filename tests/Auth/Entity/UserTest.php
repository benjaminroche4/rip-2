<?php

declare(strict_types=1);

namespace App\Tests\Auth\Entity;

use App\Auth\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit checks on User::isEqualTo() — Symfony's security layer calls it on
 * every request to verify the in-session user still matches the live DB row,
 * so getting the null-password (Google-only account) branch wrong silently
 * kicks the user back to /connexion immediately after a successful OAuth.
 *
 * The session-side `password` slot is set by {@see User::__serialize()} to
 * either the CRC32C of the live hash (classic accounts) or NULL (Google-only
 * accounts). The live side is whatever the entity repository hands back. Both
 * shapes must round-trip.
 */
final class UserTest extends TestCase
{
    public function testIsEqualToHonoursNullPasswordOnBothSides(): void
    {
        $sessionSide = $this->makeUser('google@example.com', password: null);
        $liveSide = $this->makeUser('google@example.com', password: null);

        self::assertTrue($sessionSide->isEqualTo($liveSide));
    }

    public function testIsEqualToMatchesCrc32cOfClassicPassword(): void
    {
        $hash = '$argon2id$v=19$m=65536,t=4,p=1$xxxxxx';
        $sessionSide = $this->withSessionPassword(hash('crc32c', $hash));
        $liveSide = $this->makeUser('classic@example.com', password: $hash);

        self::assertTrue($sessionSide->isEqualTo($liveSide));
    }

    public function testIsEqualToRejectsRotatedClassicPassword(): void
    {
        $oldHash = '$argon2id$v=19$m=65536,t=4,p=1$old';
        $newHash = '$argon2id$v=19$m=65536,t=4,p=1$new';
        $sessionSide = $this->withSessionPassword(hash('crc32c', $oldHash));
        $liveSide = $this->makeUser('classic@example.com', password: $newHash);

        self::assertFalse($sessionSide->isEqualTo($liveSide));
    }

    public function testIsEqualToRejectsNullVsHash(): void
    {
        // Defence in depth: an account that was Google-only must not be
        // considered equal to a classic account that happens to share the
        // same email — even though the identifier matches, the password
        // shape disagrees and the session must be invalidated.
        $sessionSide = $this->makeUser('mix@example.com', password: null);
        $liveSide = $this->makeUser('mix@example.com', password: '$argon2id$v=19$m=65536,t=4,p=1$x');

        self::assertFalse($sessionSide->isEqualTo($liveSide));
    }

    public function testIsEqualToRejectsDifferentIdentifier(): void
    {
        $sessionSide = $this->makeUser('a@example.com', password: null);
        $liveSide = $this->makeUser('b@example.com', password: null);

        self::assertFalse($sessionSide->isEqualTo($liveSide));
    }

    private function makeUser(string $email, ?string $password): User
    {
        return (new User())
            ->setEmail($email)
            ->setPassword($password);
    }

    /**
     * Mirrors what {@see User::__serialize()} does in production: writes the
     * CRC32C of the live hash into the password slot so the equality check
     * compares against the freshly-recomputed CRC32C on the next request.
     */
    private function withSessionPassword(string $crc32c): User
    {
        $user = new User();
        $user->setEmail('classic@example.com');
        $user->setPassword($crc32c);

        return $user;
    }
}
