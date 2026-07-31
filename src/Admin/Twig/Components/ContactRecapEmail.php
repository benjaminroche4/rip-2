<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactListItem;
use App\Contact\Repository\ContactEventRepository;
use App\Contact\Repository\ContactRepository;
use App\Contact\Service\ContactRecapMailer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "Send the recap to the client" button on the contact detail page. Opens
 * a confirmation modal (recipient shown, optional payment link) before
 * anything leaves the house. The payment link is only offered when a
 * package is set: the link depends on it.
 */
#[AsLiveComponent(name: 'Admin:ContactRecapEmail', template: 'components/Admin/ContactRecapEmail.html.twig')]
final class ContactRecapEmail
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $contactId = 0;

    /** 'idle' | 'modal' | 'sent' */
    #[LiveProp]
    public string $state = 'idle';

    #[LiveProp(writable: true)]
    public bool $includePayment = false;

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly ContactEventRepository $events,
        private readonly ContactRecapMailer $recapMailer,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function getContact(): ?ContactListItem
    {
        return $this->repository->listByIds([$this->contactId])[0] ?? null;
    }

    public function getPaymentAvailable(): bool
    {
        $offer = $this->getContact()?->offer;

        return null !== $offer && isset(ContactRecapMailer::PAYMENT_LINKS[$offer]);
    }

    #[LiveAction]
    public function openModal(): void
    {
        $this->ensureAdmin();
        $this->state = 'modal';
        $this->includePayment = false;
    }

    #[LiveAction]
    public function closeModal(): void
    {
        $this->ensureAdmin();
        $this->state = 'idle';
        $this->includePayment = false;
    }

    #[LiveAction]
    public function togglePayment(): void
    {
        $this->ensureAdmin();
        $this->includePayment = $this->getPaymentAvailable() && !$this->includePayment;
    }

    #[LiveAction]
    public function send(): void
    {
        $this->ensureAdmin();

        $contact = $this->getContact();
        if (null === $contact) {
            $this->state = 'idle';

            return;
        }

        $withPayment = $this->includePayment && $this->getPaymentAvailable();
        $this->recapMailer->send($contact, $withPayment);

        // Trace who sent what in the follow-up thread.
        $entity = $this->repository->find($this->contactId);
        if (null !== $entity) {
            $user = $this->security->getUser();
            $fullName = null;
            $avatar = null;
            if ($user instanceof User) {
                $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? '')) ?: $user->getEmail();
                $avatar = $user->getAvatarFilename();
            }
            $this->events->recordRecapSent($entity, $withPayment, $fullName, $avatar);
        }

        $this->state = 'sent';
        $this->emit('contacts:changed');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
