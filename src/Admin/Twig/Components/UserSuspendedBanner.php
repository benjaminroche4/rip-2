<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Alert banner at the top of the admin user profile while the account is
 * suspended. Live: it appears and disappears with the suspend/resume
 * actions of Admin:UserDanger, no page reload. Admin-only.
 */
#[AsLiveComponent(name: 'Admin:UserSuspendedBanner', template: 'components/Admin/UserSuspendedBanner.html.twig')]
final class UserSuspendedBanner
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $userId = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function isSuspended(): bool
    {
        return $this->target()->isSuspended();
    }

    /** Re-render on the suspend/resume signal. The empty body is enough. */
    #[LiveListener('user:suspension-changed')]
    public function onSuspensionChanged(): void
    {
        $this->ensureAdmin();
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
