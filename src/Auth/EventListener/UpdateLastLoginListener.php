<?php

declare(strict_types=1);

namespace App\Auth\EventListener;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

/**
 * Stamps User.lastLoginAt on every interactive authentication. We listen
 * to InteractiveLoginEvent on purpose: it fires for form_login + OAuth
 * (real connections) but NOT for silent remember_me re-auths, which would
 * otherwise update the column on every page view of a remembered session.
 */
final readonly class UpdateLastLoginListener
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[AsEventListener(InteractiveLoginEvent::class)]
    public function __invoke(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();
        if (!$user instanceof User) {
            return;
        }

        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
