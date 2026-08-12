<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use App\Auth\Service\AvatarDownloader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Sensitive zone at the bottom of the admin user profile: the admin grant
 * switch, the account suspension and the account deletion, each behind a
 * confirmation modal (except un-suspending), all with a self-guard.
 * Contacts and dossiers survive a deletion (their user FKs are ON DELETE
 * SET NULL); reset-password requests and the avatar file are purged with
 * the account. Every mutation is written to the security audit channel.
 */
#[AsLiveComponent(name: 'Admin:UserDanger', template: 'components/Admin/UserDanger.html.twig')]
final class UserDanger
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $userId = 0;

    /** Route parameter needed to build the redirect back to the users list. */
    #[LiveProp]
    public string $adminPrefix = '';

    /** True while the delete confirmation modal is open. */
    #[LiveProp]
    public bool $confirmingDelete = false;

    /** True while the admin grant/revoke confirmation modal is open. */
    #[LiveProp]
    public bool $confirmingAdmin = false;

    /** True while the suspension confirmation modal is open. */
    #[LiveProp]
    public bool $confirmingSuspend = false;

    /** True while the resume confirmation modal is open. */
    #[LiveProp]
    public bool $confirmingResume = false;

    /** True while the 2FA reset confirmation modal is open. */
    #[LiveProp]
    public bool $confirmingTwoFactorReset = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AvatarDownloader $avatarDownloader,
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function getTargetName(): string
    {
        $user = $this->target();
        $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));

        return '' !== $fullName ? $fullName : (string) $user->getEmail();
    }

    public function isSelf(): bool
    {
        $current = $this->security->getUser();

        return $current instanceof User && (int) $current->getId() === $this->userId;
    }

    public function isTargetAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->target()->getRoles(), true);
    }

    /** Granting or revoking admin is always confirmed by a modal. */
    #[LiveAction]
    public function askToggleAdmin(): void
    {
        $this->ensureAdmin();

        // Self-lockout guard: an admin can never touch their own admin role.
        if ($this->isSelf()) {
            throw new AccessDeniedException('Cannot change your own admin role.');
        }
        $this->confirmingAdmin = true;
    }

    #[LiveAction]
    public function cancelToggleAdmin(): void
    {
        $this->ensureAdmin();
        $this->confirmingAdmin = false;
    }

    #[LiveAction]
    public function confirmToggleAdmin(): void
    {
        $this->ensureAdmin();

        $this->confirmingAdmin = false;
        if ($this->isSelf()) {
            throw new AccessDeniedException('Cannot change your own admin role.');
        }

        $user = $this->target();
        $roles = $user->getRoles();
        if (\in_array('ROLE_ADMIN', $roles, true)) {
            $roles = array_filter($roles, static fn (string $r): bool => 'ROLE_ADMIN' !== $r);
        } else {
            $roles[] = 'ROLE_ADMIN';
        }
        // ROLE_USER is granted implicitly by getRoles(), no need to store it.
        $granted = \in_array('ROLE_ADMIN', $roles, true);
        $user->setRoles(array_values(array_unique(array_filter($roles, static fn (string $r): bool => 'ROLE_USER' !== $r))));
        $this->em->flush();

        $this->audit($granted ? 'admin access granted' : 'admin access revoked', $user);

        // The access card locks/unlocks its section toggles based on the
        // target's admin status: tell it to re-render. The page itself also
        // re-visits in Turbo (staff-only cards may appear or vanish).
        $this->emit('user:access-changed');
        $this->dispatchBrowserEvent('user-access:changed');
    }

    public function isTargetSuspended(): bool
    {
        return $this->target()->isSuspended();
    }

    /** Suspending and resuming are both confirmed by a modal. */
    #[LiveAction]
    public function askSuspend(): void
    {
        $this->ensureAdmin();

        if ($this->isSelf()) {
            throw new AccessDeniedException('Cannot suspend your own account.');
        }
        $this->confirmingSuspend = true;
    }

    #[LiveAction]
    public function cancelSuspend(): void
    {
        $this->ensureAdmin();
        $this->confirmingSuspend = false;
    }

    #[LiveAction]
    public function confirmSuspend(): void
    {
        $this->ensureAdmin();

        $this->confirmingSuspend = false;
        if ($this->isSelf()) {
            throw new AccessDeniedException('Cannot suspend your own account.');
        }

        $user = $this->target();
        $user->setSuspended(true);
        $this->em->flush();

        $this->audit('account suspended', $user);
        $this->emit('user:suspension-changed');
    }

    #[LiveAction]
    public function askResume(): void
    {
        $this->ensureAdmin();
        $this->confirmingResume = true;
    }

    #[LiveAction]
    public function cancelResume(): void
    {
        $this->ensureAdmin();
        $this->confirmingResume = false;
    }

    #[LiveAction]
    public function confirmResume(): void
    {
        $this->ensureAdmin();

        $this->confirmingResume = false;
        $user = $this->target();
        if ($user->isSuspended()) {
            $user->setSuspended(false);
            $this->em->flush();

            $this->audit('account resumed', $user);
            $this->emit('user:suspension-changed');
        }
    }

    public function hasTargetTwoFactor(): bool
    {
        return $this->target()->isTotpAuthenticationEnabled();
    }

    /**
     * Last-resort unlock for a member who lost both their phone and their
     * recovery codes: clears the TOTP secret and kills every trusted-device
     * cookie. Always confirmed by a modal, always audited.
     */
    #[LiveAction]
    public function askResetTwoFactor(): void
    {
        $this->ensureAdmin();

        // Never reset your own 2FA from here: it would clear the second
        // factor without any re-authentication (stolen-session takeover).
        // Losing your own device goes through another admin.
        if ($this->isSelf()) {
            throw new AccessDeniedException('Reset your own 2FA from another admin account.');
        }
        $this->confirmingTwoFactorReset = true;
    }

    #[LiveAction]
    public function cancelResetTwoFactor(): void
    {
        $this->ensureAdmin();
        $this->confirmingTwoFactorReset = false;
    }

    #[LiveAction]
    public function confirmResetTwoFactor(): void
    {
        $this->ensureAdmin();
        if ($this->isSelf()) {
            throw new AccessDeniedException('Reset your own 2FA from another admin account.');
        }

        $this->confirmingTwoFactorReset = false;
        $user = $this->target();
        if (!$user->isTotpAuthenticationEnabled()) {
            return;
        }

        $user->setPlainTotpSecret(null);
        $user->bumpTrustedTokenVersion();
        $this->em->flush();

        $this->audit('two-factor authentication reset', $user);
        // The 2FA badge lives in the page chrome: morph it.
        $this->dispatchBrowserEvent('user-access:changed');
    }

    /** Deleting an account is always confirmed by a modal. */
    #[LiveAction]
    public function askDelete(): void
    {
        $this->ensureAdmin();

        if ($this->isSelf()) {
            throw new AccessDeniedException('Cannot delete your own account.');
        }
        $this->confirmingDelete = true;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->ensureAdmin();
        $this->confirmingDelete = false;
    }

    #[LiveAction]
    public function confirmDelete(): RedirectResponse
    {
        $this->ensureAdmin();

        $this->confirmingDelete = false;
        if ($this->isSelf()) {
            throw new AccessDeniedException('Cannot delete your own account.');
        }

        $user = $this->target();

        // Reset-password requests hold a non-nullable FK on the user.
        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class.' r WHERE r.user = :user')
            ->setParameter('user', $user)
            ->execute();

        if (null !== $user->getAvatarFilename()) {
            $this->avatarDownloader->delete($user->getAvatarFilename());
        }

        $this->audit('account deleted', $user);

        $this->em->remove($user);
        $this->em->flush();

        return new RedirectResponse($this->urlGenerator->generate('admin_users', ['adminPrefix' => $this->adminPrefix]));
    }

    /** Audit trail: who did what to whom (security channel, never PII-heavy). */
    private function audit(string $action, User $targetUser): void
    {
        $this->securityLogger->notice('User administration: '.$action, [
            'actor' => $this->security->getUser()?->getUserIdentifier(),
            'target' => (string) $targetUser->getEmail(),
            'roles' => $targetUser->getRoles(),
        ]);
    }

    private function target(): User
    {
        return $this->em->find(User::class, $this->userId)
            ?? throw new NotFoundHttpException('User not found.');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
