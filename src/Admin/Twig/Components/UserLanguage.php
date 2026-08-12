<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Domain\Language;
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
 * Language tile, used both on the admin user profile and on "Mon profil".
 * Read-only by default: the locale chips only appear after the pencil is
 * clicked, like the identity card, so a stray click can never silently
 * switch someone's language.
 *
 * The stored language drives the back-office locale (see
 * AdminLocaleListener) and the locale of transactional emails. Editing
 * one's own is open to any staff member; editing someone else's needs
 * ROLE_ADMIN.
 */
#[AsLiveComponent(name: 'Admin:UserLanguage', template: 'components/Admin/UserLanguage.html.twig')]
final class UserLanguage
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $userId = 0;

    #[LiveProp]
    public bool $editing = false;

    /** "Mon profil" already labels the row through its <dt>. */
    #[LiveProp]
    public bool $showLabel = true;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->ensureCanEdit();
    }

    public function getLanguage(): ?Language
    {
        return $this->target()->getLanguage();
    }

    #[LiveAction]
    public function startEditing(): void
    {
        $this->ensureCanEdit();
        $this->editing = true;
    }

    #[LiveAction]
    public function cancelEditing(): void
    {
        $this->ensureCanEdit();
        $this->editing = false;
    }

    /**
     * Only reachable from the open editor: picking a chip saves and closes
     * it, so the tile is never one stray click away from a change.
     */
    #[LiveAction]
    public function chooseLanguage(#[LiveArg] string $language): void
    {
        $this->ensureCanEdit();
        if (!$this->editing) {
            throw new AccessDeniedException('Language editor is closed.');
        }

        $choice = Language::tryFrom($language)
            ?? throw new NotFoundHttpException('Unknown language.');

        $user = $this->target();
        if ($choice !== $user->getLanguage()) {
            $user->setLanguage($choice);
            $this->em->flush();
        }

        $this->editing = false;
    }

    private function target(): User
    {
        return $this->em->find(User::class, $this->userId)
            ?? throw new NotFoundHttpException('User not found.');
    }

    /**
     * Own language: any staff member. Someone else's: admins only.
     */
    private function ensureCanEdit(): void
    {
        if (!$this->security->isGranted('ROLE_STAFF')) {
            throw new AccessDeniedException('Back-office access required.');
        }

        $current = $this->security->getUser();
        $isSelf = $current instanceof User && (int) $current->getId() === $this->userId;
        if (!$isSelf && !$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required to change someone else language.');
        }
    }
}
