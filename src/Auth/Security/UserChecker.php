<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Auth\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Blocks suspended accounts at authentication time (form login, Google
 * OAuth and remember_me all go through the user checker). Open sessions
 * are killed separately by User::isEqualTo() comparing the flag.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && $user->isSuspended()) {
            throw new CustomUserMessageAccountStatusException('account.suspended');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
