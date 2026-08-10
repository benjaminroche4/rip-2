<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Big page heading of the contact detail: the lead's name, kept in sync
 * live with the identity edits (ContactDetails emits "contacts:changed"
 * after a save), no page reload needed.
 */
#[AsLiveComponent(name: 'Admin:ContactHeading', template: 'components/Admin/ContactHeading.html.twig')]
final class ContactHeading
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $contactId = 0;

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function getDisplayName(): string
    {
        $contact = $this->repository->listByIds([$this->contactId])[0] ?? null;
        if (null === $contact) {
            return '';
        }

        return $contact->fullName() ?: $contact->email;
    }

    /** Re-render on any contact mutation. The empty body is enough. */
    #[LiveListener('contacts:changed')]
    public function onContactsChanged(): void
    {
        $this->ensureAdmin();
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_CONTACTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
