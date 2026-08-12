<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Auth\Entity\User;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Bridge\Monolog\Attribute\WithMonologChannel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Hard cap on 2FA code attempts (6 digits are guessable otherwise), keyed
 * by account + IP so an attacker cannot lock the whole team out. Successful
 * validation resets the counter; failures are logged on the security channel.
 */
#[WithMonologChannel('security')]
final class TwoFactorAttemptThrottleListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $twoFactorAttemptsLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::ATTEMPT => 'onAttempt',
            TwoFactorAuthenticationEvents::FAILURE => 'onFailure',
            TwoFactorAuthenticationEvents::SUCCESS => 'onSuccess',
        ];
    }

    public function onAttempt(TwoFactorAuthenticationEvent $event): void
    {
        if (!$this->limiter($event)->consume()->isAccepted()) {
            $this->logger->warning('2FA attempt throttled.', ['user' => $this->username($event)]);

            // Rendered by the challenge template through the bundle's
            // translation domain, like code_invalid.
            throw new CustomUserMessageAuthenticationException('code_throttled');
        }
    }

    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $this->logger->warning('2FA code rejected.', ['user' => $this->username($event)]);
    }

    public function onSuccess(TwoFactorAuthenticationEvent $event): void
    {
        $this->limiter($event)->reset();
        $this->logger->info('2FA code accepted.', ['user' => $this->username($event)]);
    }

    private function limiter(TwoFactorAuthenticationEvent $event): \Symfony\Component\RateLimiter\LimiterInterface
    {
        return $this->twoFactorAttemptsLimiter->create(
            $this->username($event).'|'.($event->getRequest()->getClientIp() ?? 'unknown'),
        );
    }

    private function username(TwoFactorAuthenticationEvent $event): string
    {
        $user = $event->getToken()->getUser();

        return $user instanceof User ? (string) $user->getEmail() : $event->getToken()->getUserIdentifier();
    }
}
