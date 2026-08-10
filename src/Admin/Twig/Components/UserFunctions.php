<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Domain\StaffFunction;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "Fonctions" card on the admin user profile: one toggle per business
 * function (search agent, visit agent, closer), saved on click, same
 * interaction as the access card. Admin-only.
 */
#[AsLiveComponent(name: 'Admin:UserFunctions', template: 'components/Admin/UserFunctions.html.twig')]
final class UserFunctions
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

    /**
     * @return list<StaffFunction>
     */
    public function getFunctions(): array
    {
        return StaffFunction::cases();
    }

    public function hasFunction(string $value): bool
    {
        $function = StaffFunction::tryFrom($value);

        return null !== $function && $this->target()->hasStaffFunction($function);
    }

    /** Toggles a business function; takes effect immediately. */
    #[LiveAction]
    public function toggleFunction(#[LiveArg] string $function): void
    {
        $this->ensureAdmin();

        $case = StaffFunction::tryFrom($function)
            ?? throw new NotFoundHttpException('Unknown staff function.');

        $user = $this->target();
        $functions = $user->getStaffFunctions();
        if ($user->hasStaffFunction($case)) {
            $functions = array_values(array_filter($functions, static fn (StaffFunction $f): bool => $f !== $case));
        } else {
            $functions[] = $case;
        }
        $user->setStaffFunctions($functions);
        $this->em->flush();
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
