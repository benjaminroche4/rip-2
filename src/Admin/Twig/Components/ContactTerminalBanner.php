<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Contact\Domain\ContactListItem;
use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Terminal-state banner on the contact detail page, shown only for closed,
 * unqualified and converted submissions. Live so it appears/disappears/updates as
 * soon as the status or the closure reason changes.
 */
#[AsLiveComponent(name: 'Admin:ContactTerminalBanner', template: 'components/Admin/ContactTerminalBanner.html.twig')]
final class ContactTerminalBanner
{
    use ContactsSectionGuard;
    use DefaultActionTrait;

    #[LiveProp]
    public int $contactId = 0;

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly \App\Contact\Repository\ContactEventRepository $events,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[LiveListener('contacts:changed')]
    public function refresh(): void
    {
        // Re-render is the whole point.
        $this->ensureAdmin();
    }

    public function getContact(): ?ContactListItem
    {
        return $this->repository->listByIds([$this->contactId])[0] ?? null;
    }

    /**
     * Qui a posé l'état terminal, lu sur le fil de suivi (chaque entrée porte
     * déjà le nom et l'avatar de son auteur).
     *
     * @return array{name: string, avatar: ?string}|null
     */
    public function getStatusAuthor(): ?array
    {
        $status = $this->getContact()?->status;

        return null !== $status ? $this->events->findStatusAuthor($this->contactId, $status) : null;
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_CONTACTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
