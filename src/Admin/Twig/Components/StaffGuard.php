<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\PreReRender;

/**
 * Role gate for staff-level live components (profile cards available to
 * every back-office user). The /_components/ routes bypass the URL-based
 * access_control, and each action already re-checks the role by hand,
 * but the plain re-render path (a POST with no action) used to reach the
 * template unchecked: a revoked user replaying a saved component payload
 * could keep reading back-office data. The PreReRender hook closes that
 * path for good.
 *
 * Requires a $security property (Symfony\Bundle\SecurityBundle\Security)
 * on the composing class.
 */
trait StaffGuard
{
    #[PreReRender]
    public function denyUnlessStaff(): void
    {
        if (!$this->security->isGranted('ROLE_STAFF')) {
            throw new AccessDeniedException('Staff access required.');
        }
    }
}
