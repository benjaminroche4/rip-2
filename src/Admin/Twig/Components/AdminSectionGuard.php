<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\PreReRender;

/**
 * Role gate for every admin-only live component (user management). The
 * /_components/ routes bypass the URL-based access_control, and each
 * action already re-checks the role by hand, but the plain re-render
 * path (a POST with no action) used to reach the template unchecked: a
 * revoked user replaying a saved component payload could keep reading
 * user-administration data. The PreReRender hook closes that path for
 * good.
 *
 * Requires a $security property (Symfony\Bundle\SecurityBundle\Security)
 * on the composing class.
 */
trait AdminSectionGuard
{
    #[PreReRender]
    public function denyUnlessAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
