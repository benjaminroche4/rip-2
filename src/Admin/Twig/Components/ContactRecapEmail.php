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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Send the recap to the client" button on the contact detail page. Opens
 * a confirmation modal (recipient shown, optional payment link) before
 * anything leaves the house. The payment link is only offered when a
 * package is set: the link depends on it.
 */
#[AsLiveComponent(name: 'Admin:ContactRecapEmail', template: 'components/Admin/ContactRecapEmail.html.twig')]
final class ContactRecapEmail
{
    use ContactsSectionGuard;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public int $contactId = 0;

    /** 'idle' | 'modal' */
    #[LiveProp]
    public string $state = 'idle';

    #[LiveProp(writable: true)]
    public bool $includePayment = false;

    /** 50% deposit link instead of the full amount ("confie" only). */
    #[LiveProp(writable: true)]
    public bool $includeDeposit = false;

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly ContactEventRepository $events,
        private readonly ContactRecapMailer $recapMailer,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
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

        return null !== $offer && isset(ContactRecapMailer::PAYMENT_LINKS['fr'][$offer]);
    }

    public function getDepositAvailable(): bool
    {
        return 'confie' === $this->getContact()?->offer;
    }

    #[LiveAction]
    public function openModal(): void
    {
        $this->ensureAdmin();
        $this->state = 'modal';
        $this->includePayment = false;
        $this->includeDeposit = false;
    }

    #[LiveAction]
    public function closeModal(): void
    {
        $this->ensureAdmin();
        $this->state = 'idle';
        $this->includePayment = false;
        $this->includeDeposit = false;
    }

    #[LiveAction]
    public function togglePayment(): void
    {
        $this->ensureAdmin();
        $this->includePayment = $this->getPaymentAvailable() && !$this->includePayment;
        if (!$this->includePayment) {
            $this->includeDeposit = false;
        }
    }

    #[LiveAction]
    public function toggleDeposit(): void
    {
        $this->ensureAdmin();
        $this->includeDeposit = $this->includePayment && $this->getDepositAvailable() && !$this->includeDeposit;
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
        $withDeposit = $withPayment && $this->includeDeposit && $this->getDepositAvailable();
        $this->recapMailer->send($contact, $withPayment, $withDeposit);

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

        // Back to idle: the confirmation lives in the toast only, the menu row
        // stays available (no "sent" chip replacing the trigger).
        $this->state = 'idle';
        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('admin.contacts.recapEmail.sent'),
        ]);
        $this->emit('contacts:changed');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_CONTACTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
