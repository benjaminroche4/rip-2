<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Contact\Domain\ContactSource;
use App\Contact\Form\ContactType;
use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "New contact request" modal on the admin list: same fields as the public
 * contact form plus the language, for requests taken over the phone.
 * Redirects to the new detail page once created.
 */
#[AsLiveComponent(name: 'Admin:ContactCreate', template: 'components/Admin/ContactCreate.html.twig')]
final class ContactCreate
{
    use ContactsSectionGuard;
    use DefaultActionTrait;

    #[LiveProp]
    public string $adminPrefix = '';

    #[LiveProp]
    public bool $open = false;

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
    public string $helpType = ContactDetails::HELP_TYPES[0];

    /** '' = none. */
    #[LiveProp(writable: true)]
    public string $offer = '';

    #[LiveProp(writable: true)]
    public string $lang = 'fr';

    #[LiveProp(writable: true)]
    public string $message = '';

    #[LiveProp(writable: true)]
    public string $source = 'phone';

    public function __construct(
        private readonly ContactRepository $repository,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    /**
     * @return list<ContactSource>
     */
    public function getManualSources(): array
    {
        return ContactSource::manualCases();
    }

    #[LiveAction]
    public function openModal(): void
    {
        $this->ensureAdmin();
        $this->open = true;
    }

    #[LiveAction]
    public function closeModal(): void
    {
        $this->ensureAdmin();
        $this->open = false;
        $this->errors = [];
    }

    #[LiveAction]
    public function chooseHelpType(#[LiveArg] string $type): void
    {
        $this->ensureAdmin();

        if (!\in_array($type, ContactDetails::HELP_TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown help type "%s".', $type));
        }
        $this->helpType = $type;
        if (ContactType::HOUSING_HELP_TYPE !== $type) {
            $this->offer = '';
        }
    }

    #[LiveAction]
    public function chooseOffer(#[LiveArg] string $offer): void
    {
        $this->ensureAdmin();

        if ('' !== $offer && !\in_array($offer, ContactDetails::OFFERS, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown offer "%s".', $offer));
        }
        $this->offer = $offer;
    }

    #[LiveAction]
    public function chooseLang(#[LiveArg] string $lang): void
    {
        $this->ensureAdmin();

        if (!\in_array($lang, ContactDetails::LANGS, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown language "%s".', $lang));
        }
        $this->lang = $lang;
    }

    #[LiveAction]
    public function chooseSource(#[LiveArg] string $source): void
    {
        $this->ensureAdmin();

        $case = ContactSource::tryFrom($source);
        if (null === $case || !\in_array($case, ContactSource::manualCases(), true)) {
            throw new BadRequestHttpException(\sprintf('Unknown source "%s".', $source));
        }
        $this->source = $source;
    }

    #[LiveAction]
    public function create(): ?RedirectResponse
    {
        $this->ensureAdmin();

        // Required: first name, contact language, request type, and the
        // offer when the request is a housing search. Everything else
        // (last name, email, phone…) is optional on manual intakes; only
        // the email format is checked when something was typed.
        $this->errors = [];
        if ('' === trim($this->firstName)) {
            $this->errors['firstName'] = 'admin.contacts.edit.required';
        }
        if (ContactType::HOUSING_HELP_TYPE === $this->helpType && '' === $this->offer) {
            $this->errors['offer'] = 'admin.contacts.edit.required';
        }
        if ('' !== trim($this->email) && false === filter_var(trim($this->email), \FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = 'admin.contacts.edit.invalidEmail';
        }
        if ([] !== $this->errors) {
            return null;
        }

        if (!\in_array($this->helpType, ContactDetails::HELP_TYPES, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown help type "%s".', $this->helpType));
        }
        if ('' !== $this->offer && !\in_array($this->offer, ContactDetails::OFFERS, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown offer "%s".', $this->offer));
        }
        if (!\in_array($this->lang, ContactDetails::LANGS, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown language "%s".', $this->lang));
        }

        $sourceCase = ContactSource::tryFrom($this->source);
        if (null === $sourceCase || !\in_array($sourceCase, ContactSource::manualCases(), true)) {
            throw new BadRequestHttpException(\sprintf('Unknown source "%s".', $this->source));
        }

        $reference = $this->repository->createManual(
            trim($this->firstName),
            trim($this->lastName),
            '' !== trim($this->email) ? trim($this->email) : null,
            '' !== trim($this->phoneNumber) ? $this->normalizePhone($this->phoneNumber) : null,
            '' !== trim($this->company) ? trim($this->company) : null,
            $this->helpType,
            ContactType::HOUSING_HELP_TYPE === $this->helpType && '' !== $this->offer ? $this->offer : null,
            $this->lang,
            '' !== trim($this->message) ? trim($this->message) : null,
            $sourceCase,
        );

        return new RedirectResponse($this->urlGenerator->generate('admin_contact_show', [
            'adminPrefix' => $this->adminPrefix,
            'reference' => $reference,
        ]));
    }

    /**
     * Stores manual intakes in a searchable shape: digits only, keeping a
     * leading "+" ("06 12.34-56 78" -> "0612345678"). Phone search strips
     * separators from the query but compares against the stored column.
     */
    private function normalizePhone(string $raw): string
    {
        $normalized = (string) preg_replace('/[^0-9+]+/', '', trim($raw));

        return str_starts_with($normalized, '+') ? '+'.str_replace('+', '', $normalized) : str_replace('+', '', $normalized);
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_CONTACTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
