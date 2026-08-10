<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Contact\Domain\ContactStatus;
use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Counter badge next to the "Contacts" sidebar link showing how many
 * submissions are still untreated (status "new"). Live so it refreshes
 * itself when the contact list emits a change (status updated).
 */
#[AsLiveComponent(name: 'Admin:ContactsNavBadge', template: 'components/Admin/ContactsNavBadge.html.twig')]
final class ContactsNavBadge
{
    use ContactsSectionGuard;
    use DefaultActionTrait;

    public function __construct(
        private readonly ContactRepository $repository,
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
        // Re-render is the whole point; the count is recomputed in the template.
        $this->ensureAdmin();
    }

    public function getCount(): int
    {
        return $this->repository->countByStatus(ContactStatus::New);
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_CONTACTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
