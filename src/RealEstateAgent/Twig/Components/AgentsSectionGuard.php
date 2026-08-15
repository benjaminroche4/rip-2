<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\PreReRender;

/**
 * Role gate for every agents-section live component. The /_components/
 * routes bypass the URL-based access_control, and each action already
 * re-checks the role by hand, but the plain re-render path (a POST with
 * no action) used to reach the template unchecked: a revoked user
 * replaying a saved component payload could keep reading agent data.
 * The PreReRender hook closes that path for good.
 *
 * Requires a $security property (Symfony\Bundle\SecurityBundle\Security)
 * on the composing class.
 */
trait AgentsSectionGuard
{
    #[PreReRender]
    public function denyUnlessAgentsSection(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_AGENTS')) {
            throw new AccessDeniedException('Agents section access required.');
        }
    }
}
