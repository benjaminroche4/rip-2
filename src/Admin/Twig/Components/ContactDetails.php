<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Contact\Domain\ContactListItem;
use App\Contact\Domain\ContactSource;
use App\Contact\Form\ContactType;
use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Submitted contact details on the detail page, editable in place so the
 * admin can fix what the prospect got wrong (typo in the email, wrong
 * help type...). Server-side validation mirrors the public form rules.
 */
#[AsLiveComponent(name: 'Admin:ContactDetails', template: 'components/Admin/ContactDetails.html.twig')]
final class ContactDetails
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public const HELP_TYPES = [
        'contact.contactForm.helpType.choice.1',
        'contact.contactForm.helpType.choice.2',
        'contact.contactForm.helpType.choice.3',
        'contact.contactForm.helpType.choice.4',
    ];

    public const OFFERS = ['accompagne', 'confie'];

    public const LANGS = ['fr', 'en'];

    #[LiveProp]
    public int $contactId = 0;

    #[LiveProp]
    public bool $editing = false;

    /** Validation errors keyed by field, shown under the inputs. */
    #[LiveProp]
    public array $errors = [];

    #[LiveProp(writable: true)]
    public string $firstName = '';

    #[LiveProp(writable: true)]
    public string $lastName = '';

    #[LiveProp(writable: true)]
    public string $email = '';

    #[LiveProp(writable: true)]
    public string $phoneNumber = '';

    #[LiveProp(writable: true)]
    public string $company = '';

    #[LiveProp(writable: true)]
    public string $helpType = '';

    /** '' = none. */
    #[LiveProp(writable: true)]
    public string $offer = '';

    #[LiveProp(writable: true)]
    public string $lang = 'fr';

    #[LiveProp(writable: true)]
    public string $source = 'form';

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(int $contactId): void
    {
        $this->ensureAdmin();
        $this->contactId = $contactId;
        $this->prefill();
    }

    public function getContact(): ?ContactListItem
    {
        return $this->repository->listByIds([$this->contactId])[0] ?? null;
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
        $this->errors = [];
    }

    #[LiveAction]
    public function chooseHelpType(#[LiveArg] string $type): void
    {
        $this->ensureAdmin();

        if (!\in_array($type, self::HELP_TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown help type "%s".', $type));
        }
        $this->helpType = $type;
        // The package only exists for the housing search, like on the
        // public form.
        if (ContactType::HOUSING_HELP_TYPE !== $type) {
            $this->offer = '';
        }
    }

    #[LiveAction]
    public function chooseLang(#[LiveArg] string $lang): void
    {
        $this->ensureAdmin();

        if (!\in_array($lang, self::LANGS, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown language "%s".', $lang));
        }
        $this->lang = $lang;
    }

    #[LiveAction]
    public function chooseSource(#[LiveArg] string $source): void
    {
        $this->ensureAdmin();

        if (null === ContactSource::tryFrom($source)) {
            throw new BadRequestHttpException(\sprintf('Unknown source "%s".', $source));
        }
        $this->source = $source;
    }

    public function getSourceCase(): ContactSource
    {
        return ContactSource::tryFrom($this->source) ?? ContactSource::Form;
    }

    /**
     * @return list<ContactSource>
     */
    public function getSources(): array
    {
        return ContactSource::cases();
    }

    #[LiveAction]
    public function chooseOffer(#[LiveArg] string $offer): void
    {
        $this->ensureAdmin();

        if ('' !== $offer && !\in_array($offer, self::OFFERS, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown offer "%s".', $offer));
        }
        $this->offer = $offer;
    }

    #[LiveAction]
    public function saveDetails(): void
    {
        $this->ensureAdmin();

        $this->errors = [];
        if ('' === trim($this->firstName)) {
            $this->errors['firstName'] = 'admin.contacts.edit.required';
        }
        if ('' === trim($this->lastName)) {
            $this->errors['lastName'] = 'admin.contacts.edit.required';
        }
        // Optional (manual intakes may have no email); format-checked when present.
        if ('' !== trim($this->email) && false === filter_var(trim($this->email), \FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = 'admin.contacts.edit.invalidEmail';
        }
        if ([] !== $this->errors) {
            return;
        }

        // Closed lists never come from free input: a bad value is a forged
        // request, not a typo.
        if (!\in_array($this->helpType, self::HELP_TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown help type "%s".', $this->helpType));
        }
        if ('' !== $this->offer && !\in_array($this->offer, self::OFFERS, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown offer "%s".', $this->offer));
        }
        if (!\in_array($this->lang, self::LANGS, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown language "%s".', $this->lang));
        }
        $sourceCase = ContactSource::tryFrom($this->source)
            ?? throw new BadRequestHttpException(\sprintf('Unknown source "%s".', $this->source));

        $this->repository->updateDetails(
            $this->contactId,
            trim($this->firstName),
            trim($this->lastName),
            '' !== trim($this->email) ? trim($this->email) : null,
            '' !== trim($this->phoneNumber) ? trim($this->phoneNumber) : null,
            '' !== trim($this->company) ? trim($this->company) : null,
            $this->helpType,
            ContactType::HOUSING_HELP_TYPE === $this->helpType && '' !== $this->offer ? $this->offer : null,
            $this->lang,
            $sourceCase,
        );

        $this->prefill();
        $this->editing = false;
        // Name/email may appear elsewhere on the page (header, follow-up).
        $this->emit('contacts:changed');
    }

    private function prefill(): void
    {
        $contact = $this->getContact();
        $this->firstName = $contact->firstName ?? '';
        $this->lastName = $contact->lastName ?? '';
        $this->email = $contact->email ?? '';
        $this->phoneNumber = $contact->phoneNumber ?? '';
        $this->company = $contact->company ?? '';
        $this->helpType = $contact->helpType ?? self::HELP_TYPES[0];
        $this->offer = $contact->offer ?? '';
        $this->lang = $contact->lang ?? 'fr';
        $this->source = ($contact->source ?? ContactSource::Form)->value;
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
