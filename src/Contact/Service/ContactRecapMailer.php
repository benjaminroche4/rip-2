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
    /** Stripe payment links per package (accompagne 1 190 €, confie 2 190 €). */
    public const PAYMENT_LINKS = [
        'accompagne' => 'https://payment.relocation-in-paris.fr/b/00wfZh7hV2RLeeL6oH6kg05',
        'confie' => 'https://payment.relocation-in-paris.fr/b/00w5kDcCf4ZT2w36oH6kg04',
    ];

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private DistrictStaticMapUrl $staticMap,
    ) {
    }

    public function send(ContactListItem $contact, bool $withPaymentLink = false): void
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
                'paymentUrl' => $withPaymentLink && null !== $contact->offer ? (self::PAYMENT_LINKS[$contact->offer] ?? null) : null,
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
