<?php

declare(strict_types=1);

namespace App\Contact\Service;

use App\Contact\Domain\ContactListItem;
use App\Contact\Domain\ParisDistricts;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends the housing-project recap to the prospect, in the transactional
 * email style: project details, their contact details, and the team
 * member following their file. Written in the prospect's language.
 */
final readonly class ContactRecapMailer
{
    /**
     * Stripe payment links per locale and package (accompagne 1 190 €,
     * confie 2 190 €). The "confie" package can be paid with a 50% deposit;
     * "accompagne" has no deposit option.
     */
    public const PAYMENT_LINKS = [
        'fr' => [
            'accompagne' => ['full' => 'https://payment.relocation-in-paris.fr/b/00wfZh7hV2RLeeL6oH6kg05'],
            'confie' => [
                'full' => 'https://payment.relocation-in-paris.fr/b/00w5kDcCf4ZT2w36oH6kg04',
                'deposit' => 'https://payment.relocation-in-paris.fr/b/eVq4gz9q3akd8Ur4gz6kg01',
            ],
        ],
        'en' => [
            'accompagne' => ['full' => 'https://payment.relocation-in-paris.fr/b/4gMbJ10Tx63X2w314n6kg02'],
            'confie' => [
                'full' => 'https://payment.relocation-in-paris.fr/b/4gM5kDgSv4ZTeeL3cv6kg00',
                'deposit' => 'https://payment.relocation-in-paris.fr/b/aFa00j0Tx781b2z3cv6kg03',
            ],
        ],
    ];

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private DistrictStaticMapUrl $staticMap,
    ) {
    }

    public function send(ContactListItem $contact, bool $withPaymentLink = false, bool $withDeposit = false): void
    {
        $locale = \in_array($contact->lang, ['fr', 'en'], true) ? $contact->lang : 'fr';
        $fr = 'fr' === $locale;

        $email = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to((string) $contact->email)
            ->subject($fr
                ? 'Votre projet logement à Paris | Relocation in Paris'
                : 'Your housing project in Paris | Relocation in Paris')
            ->htmlTemplate('emails/contact_recap.html.twig')
            ->context([
                'locale' => $locale,
                'contact' => $contact,
                'areaLabels' => $this->areaLabels($contact),
                'areasMapUrl' => $this->staticMap->build($this->areaCodes($contact), $locale),
                'paymentUrl' => $this->paymentUrl($contact, $locale, $withPaymentLink, $withDeposit),
                'paymentDeposit' => $withDeposit && 'confie' === $contact->offer,
                'paymentOfferTitle' => null !== $contact->offer ? $this->translator->trans('contact.contactForm.offer.'.$contact->offer.'.title', locale: $locale) : null,
                'paymentOfferPrice' => null !== $contact->offer ? $this->translator->trans('contact.contactForm.offer.'.$contact->offer.'.price', locale: $locale) : null,
                'propertyTypeLabels' => $this->propertyTypeLabels($contact, $locale),
                'offerLabel' => null !== $contact->offer
                    ? \sprintf(
                        '%s (%s)',
                        $this->translator->trans('contact.contactForm.offer.'.$contact->offer.'.title', locale: $locale),
                        $this->translator->trans('contact.contactForm.offer.'.$contact->offer.'.price', locale: $locale),
                    )
                    : null,
            ]);

        $this->mailer->send($email);
    }

    private function paymentUrl(ContactListItem $contact, string $locale, bool $withPaymentLink, bool $withDeposit): ?string
    {
        if (!$withPaymentLink || null === $contact->offer) {
            return null;
        }

        $links = self::PAYMENT_LINKS[$locale][$contact->offer] ?? null;
        if (null === $links) {
            return null;
        }

        // The deposit only exists for the "confie" package.
        return $withDeposit && isset($links['deposit']) ? $links['deposit'] : $links['full'];
    }

    /**
     * @return list<string>
     */
    private function areaLabels(ContactListItem $contact): array
    {
        return array_map(
            static fn (string $code): string => ParisDistricts::LABELS[$code] ?? $code,
            $this->areaCodes($contact),
        );
    }

    /**
     * @return list<string>
     */
    private function areaCodes(ContactListItem $contact): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', (string) $contact->projectAreas))));
    }

    /**
     * @return list<string>
     */
    private function propertyTypeLabels(ContactListItem $contact, string $locale): array
    {
        $labels = [];
        foreach (array_filter(explode(',', (string) $contact->projectPropertyType)) as $value) {
            $key = 'listProperty.form.propertyType.choice.'.$value;
            $label = $this->translator->trans($key, locale: $locale);
            $labels[] = $label !== $key ? $label : $value;
        }

        return $labels;
    }
}
