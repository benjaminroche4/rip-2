<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "Accès au back-office" card on the admin user profile: a master switch
 * grants ROLE_STAFF and reveals one toggle per business section (saved on
 * click). Turning the master off strips ROLE_STAFF and every section, behind
 * a confirmation modal (the session dies on the target's next request). The
 * admin switch lives in the sensitive zone at the bottom of the page
 * (Admin:UserDanger). Admin-only.
 */
#[AsLiveComponent(name: 'Admin:UserAccess', template: 'components/Admin/UserAccess.html.twig')]
final class UserAccess
{
    use AdminSectionGuard;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /** Section key → stored role. */
    public const SECTIONS = [
        'contacts' => 'ROLE_SECTION_CONTACTS',
        'dossiers' => 'ROLE_SECTION_DOSSIERS',
        'visits' => 'ROLE_SECTION_VISITS',
        'agents' => 'ROLE_SECTION_AGENTS',
        'tools' => 'ROLE_SECTION_TOOLS',
    ];

    #[LiveProp]
    public int $userId = 0;

    /** True while the back-office grant confirmation modal is open. */
    #[LiveProp]
    public bool $confirmingGrant = false;

    /** True while the back-office revocation confirmation modal is open. */
    #[LiveProp]
    public bool $confirmingRevoke = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly LoggerInterface $securityLogger,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function isTargetAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->target()->getRoles(), true);
    }

    public function getTargetName(): string
    {
        $user = $this->target();
        $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));

        return '' !== $fullName ? $fullName : (string) $user->getEmail();
    }

    /** True when the target holds any stored back-office role. */
    public function hasBackOfficeAccess(): bool
    {
        foreach ($this->target()->getRoles() as $role) {
            if ('ROLE_ADMIN' === $role || 'ROLE_STAFF' === $role || str_starts_with($role, 'ROLE_SECTION_')) {
                return true;
            }
        }

        return false;
    }

    /** Turning the master switch on is confirmed by a modal too. */
    #[LiveAction]
    public function askGrantBackOffice(): void
    {
        $this->ensureAdmin();
        $this->confirmingGrant = true;
    }

    #[LiveAction]
    public function cancelGrantBackOffice(): void
    {
        $this->ensureAdmin();
        $this->confirmingGrant = false;
    }

    #[LiveAction]
    public function confirmGrantBackOffice(): void
    {
        $this->ensureAdmin();

        $this->confirmingGrant = false;
        $user = $this->target();
        $user->setRoles($this->normalize([...$user->getRoles(), 'ROLE_STAFF']));
        $this->em->flush();

        $this->audit('back-office access granted', $user);

        // La fiche montre/masque les cards staff (fonctions, activité) : on
        // la rafraîchit en Turbo, sans rechargement manuel.
        $this->dispatchBrowserEvent('user-access:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.accessGranted')]);
    }

    /** Turning it off strips everything: always confirmed by a modal. */
    #[LiveAction]
    public function askRevokeBackOffice(): void
    {
        $this->ensureAdmin();

        // An admin always has back-office access: remove admin first, in the
        // sensitive zone, behind its own confirmation.
        if ($this->isTargetAdmin()) {
            throw new AccessDeniedException('Remove the admin grant first.');
        }
        $this->confirmingRevoke = true;
    }

    #[LiveAction]
    public function cancelRevokeBackOffice(): void
    {
        $this->ensureAdmin();
        $this->confirmingRevoke = false;
    }

    #[LiveAction]
    public function confirmRevokeBackOffice(): void
    {
        $this->ensureAdmin();

        $this->confirmingRevoke = false;
        if ($this->isTargetAdmin()) {
            throw new AccessDeniedException('Remove the admin grant first.');
        }

        $user = $this->target();
        $user->setRoles($this->normalize(array_filter(
            $user->getRoles(),
            static fn (string $r): bool => 'ROLE_STAFF' !== $r && !str_starts_with($r, 'ROLE_SECTION_'),
        )));
        $this->em->flush();

        $this->audit('back-office access revoked', $user);

        $this->dispatchBrowserEvent('user-access:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.accessRevoked')]);
    }

    public function hasSection(string $section): bool
    {
        $role = self::SECTIONS[$section] ?? '';

        return '' !== $role && \in_array($role, $this->target()->getRoles(), true);
    }

    /**
     * The admin switch lives in Admin:UserDanger; granting or revoking admin
     * locks/unlocks the section toggles here, so re-render on its signal.
     * The empty body is enough: receiving the event triggers a re-render.
     */
    #[LiveListener('user:access-changed')]
    public function onAccessChanged(): void
    {
        $this->ensureAdmin();
    }

    /** Toggles a business-section access; takes effect immediately. */
    #[LiveAction]
    public function toggleSection(#[LiveArg] string $section): void
    {
        $this->ensureAdmin();

        $role = self::SECTIONS[$section] ?? throw new NotFoundHttpException('Unknown back-office section.');
        $user = $this->target();

        $roles = $user->getRoles();
        if (\in_array($role, $roles, true)) {
            $roles = array_filter($roles, static fn (string $r): bool => $r !== $role);
            $action = 'section revoked';
        } else {
            $roles[] = $role;
            $action = 'section granted';
        }
        $user->setRoles($this->normalize($roles));
        $this->em->flush();

        $this->audit($action.' ('.$section.')', $user);
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

    /**
     * ROLE_USER is granted implicitly by getRoles(), no need to store it.
     *
     * @param array<string> $roles
     *
     * @return list<string>
     */
    private function normalize(array $roles): array
    {
        return array_values(array_unique(array_filter($roles, static fn (string $r): bool => 'ROLE_USER' !== $r)));
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
