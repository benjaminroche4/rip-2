<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\Entity\User;
use App\Auth\Security\TwoFactorAttemptThrottleListener;
use Monolog\Attribute\WithMonologChannel;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Brute-force cap on 2FA code attempts: keyed by account + IP (an attacker
 * must not be able to lock the whole team out), reset on success, failures
 * logged on the security channel. Uses a real RateLimiterFactory over an
 * in-memory storage with the production shape (fixed window, 5 attempts).
 */
final class TwoFactorAttemptThrottleListenerTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array}> */
    private array $logRecords = [];

    public function testItBlocksTheSixthAttemptForTheSameAccountAndIp(): void
    {
        $listener = $this->listener();

        for ($i = 0; $i < 5; ++$i) {
            $listener->onAttempt($this->event('staff@example.com', '10.0.0.1'));
        }

        try {
            $listener->onAttempt($this->event('staff@example.com', '10.0.0.1'));
            self::fail('The sixth attempt must be throttled.');
        } catch (CustomUserMessageAuthenticationException $e) {
            // Message key rendered by the challenge template, like code_invalid.
            self::assertSame('code_throttled', $e->getMessage());
        }

        $warnings = array_filter($this->logRecords, static fn (array $r): bool => '2FA attempt throttled.' === $r['message']);
        self::assertCount(1, $warnings, 'The throttled attempt must be logged.');
        self::assertSame('staff@example.com', array_values($warnings)[0]['context']['user']);
    }

    public function testAnotherIpOrAccountIsNotLockedOut(): void
    {
        $listener = $this->listener();

        // Exhaust the cap for one account + IP pair.
        for ($i = 0; $i < 5; ++$i) {
            $listener->onAttempt($this->event('staff@example.com', '10.0.0.1'));
        }

        // Same account from another IP, and another account from the hot
        // IP, both keep their own budget.
        $listener->onAttempt($this->event('staff@example.com', '10.0.0.2'));
        $listener->onAttempt($this->event('colleague@example.com', '10.0.0.1'));
        $this->addToAssertionCount(2);
    }

    public function testASuccessfulValidationResetsTheCounter(): void
    {
        $listener = $this->listener();

        for ($i = 0; $i < 4; ++$i) {
            $listener->onAttempt($this->event('staff@example.com', '10.0.0.1'));
        }
        $listener->onSuccess($this->event('staff@example.com', '10.0.0.1'));

        // A fresh full budget is available again after the reset.
        for ($i = 0; $i < 5; ++$i) {
            $listener->onAttempt($this->event('staff@example.com', '10.0.0.1'));
        }
        $this->addToAssertionCount(5);
    }

    public function testRejectedCodesAreLogged(): void
    {
        $listener = $this->listener();

        $listener->onFailure($this->event('staff@example.com', '10.0.0.1'));

        self::assertSame('warning', $this->logRecords[0]['level']);
        self::assertSame('2FA code rejected.', $this->logRecords[0]['message']);
        self::assertSame('staff@example.com', $this->logRecords[0]['context']['user']);
    }

    public function testTheListenerLogsOnTheSecurityChannel(): void
    {
        $attributes = (new \ReflectionClass(TwoFactorAttemptThrottleListener::class))
            ->getAttributes(WithMonologChannel::class);

        self::assertCount(1, $attributes);
        self::assertSame('security', $attributes[0]->newInstance()->channel);
    }

    private function listener(): TwoFactorAttemptThrottleListener
    {
        // Production shape of the two_factor_attempts limiter.
        $factory = new RateLimiterFactory([
            'id' => 'two_factor_attempts',
            'policy' => 'fixed_window',
            'limit' => 5,
            'interval' => '15 minutes',
        ], new InMemoryStorage());

        $sink = function (string $level, string $message, array $context): void {
            $this->logRecords[] = ['level' => $level, 'message' => $message, 'context' => $context];
        };
        $logger = new class($sink) extends AbstractLogger {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->sink)((string) $level, (string) $message, $context);
            }
        };

        return new TwoFactorAttemptThrottleListener($factory, $logger);
    }

    private function event(string $email, string $ip): TwoFactorAuthenticationEvent
    {
        $user = (new User())->setEmail($email);
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_STAFF']);
        $request = Request::create('/2fa_check', 'POST', server: ['REMOTE_ADDR' => $ip]);

        return new TwoFactorAuthenticationEvent($request, $token);
    }
}
