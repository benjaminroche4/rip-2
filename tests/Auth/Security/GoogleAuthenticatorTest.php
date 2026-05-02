<?php

namespace App\Tests\Auth\Security;

use App\Auth\Security\GoogleAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;

/**
 * Locks down the interactive flag of GoogleAuthenticator. Required so
 * Symfony's AuthenticatorManager dispatches InteractiveLoginEvent on
 * Google logins (otherwise UpdateLastLoginListener would never fire).
 */
final class GoogleAuthenticatorTest extends TestCase
{
    public function testIsInteractiveSoSecurityDispatchesInteractiveLoginEvent(): void
    {
        $reflection = new \ReflectionClass(GoogleAuthenticator::class);

        self::assertTrue(
            $reflection->implementsInterface(InteractiveAuthenticatorInterface::class),
            'GoogleAuthenticator must implement InteractiveAuthenticatorInterface so InteractiveLoginEvent fires.',
        );

        $isInteractive = $reflection->getMethod('isInteractive')->invoke(
            $reflection->newInstanceWithoutConstructor(),
        );
        self::assertTrue($isInteractive, 'isInteractive() must return true.');
    }
}
