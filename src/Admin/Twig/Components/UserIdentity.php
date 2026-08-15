<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Entity\User;
use App\Auth\Service\AvatarDownloader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Identity header on the admin user profile, editable in place with the
 * same pattern as ContactDetails: a pencil opens the edit mode, explicit
 * save/cancel. The avatar can be replaced (plain multipart POST to
 * admin_user_avatar, LiveComponents don't carry files) or reset. Admin-only.
 */
#[AsLiveComponent(name: 'Admin:UserIdentity', template: 'components/Admin/UserIdentity.html.twig')]
final class UserIdentity
{
    use AdminSectionGuard;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    private const NAME_MAX_LENGTH = 80;

    #[LiveProp]
    public int $userId = 0;

    /** Route parameters for the avatar upload form target. */
    #[LiveProp]
    public string $adminPrefix = '';

    #[LiveProp]
    public string $uniqueId = '';

    #[LiveProp]
    public bool $editing = false;

    #[LiveProp(writable: true)]
    public string $firstName = '';

    #[LiveProp(writable: true)]
    public string $lastName = '';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly AvatarDownloader $avatarDownloader,
        private readonly LoggerInterface $securityLogger,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ) {
    }

    public function mount(int $userId): void
    {
        $this->ensureAdmin();

        $this->userId = $userId;
        $this->prefill();
    }

    public function getEmail(): string
    {
        return (string) $this->target()->getEmail();
    }

    public function getAvatarFilename(): ?string
    {
        return $this->target()->getAvatarFilename();
    }

    public function getDisplayName(): string
    {
        $fullName = trim($this->firstName.' '.$this->lastName);

        return '' !== $fullName ? $fullName : $this->getEmail();
    }

    #[LiveAction]
    public function startEditing(): void
    {
        $this->ensureAdmin();
        $this->prefill();
        $this->editing = true;
    }

    #[LiveAction]
    public function cancelEditing(): void
    {
        $this->ensureAdmin();
        $this->prefill();
        $this->editing = false;
    }

    #[LiveAction]
    public function saveIdentity(): void
    {
        $this->ensureAdmin();

        $this->firstName = mb_substr(trim($this->firstName), 0, self::NAME_MAX_LENGTH);
        $this->lastName = mb_substr(trim($this->lastName), 0, self::NAME_MAX_LENGTH);

        $user = $this->target();
        $user->setFirstName($this->firstName);
        $user->setLastName($this->lastName);
        $this->em->flush();

        // Audit trail: renaming an account is identity-sensitive.
        $this->securityLogger->notice('User administration: identity updated', [
            'actor' => $this->security->getUser()?->getUserIdentifier(),
            'target' => (string) $user->getEmail(),
        ]);

        $this->editing = false;
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.saved')]);
    }

    /** Best-effort: removes the stored file, then clears the reference. */
    #[LiveAction]
    public function resetAvatar(): void
    {
        $this->ensureAdmin();

        $user = $this->target();
        if (null !== $user->getAvatarFilename()) {
            $this->avatarDownloader->delete($user->getAvatarFilename());
            $user->setAvatarFilename(null);
            $this->em->flush();
        }
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.avatarReset')]);
    }

    private function prefill(): void
    {
        $user = $this->target();
        $this->firstName = (string) $user->getFirstName();
        $this->lastName = (string) $user->getLastName();
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
