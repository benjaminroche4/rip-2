<?php

declare(strict_types=1);

namespace App\Contact\Service;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactListItem;
use App\Contact\Domain\ParisDistricts;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends the housing-project recap to the prospect, in the transactional
 * email style: project details, their contact details, and the team
 * member following their file. Written in the prospect's language, and
 * sent from the assigned closer's own address when it lives on the
 * agency domain (CloserSender rules, same as the visio invitation).
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
            'accompagne' => ['full' => 'https://payment.relocation-in-paris.fr/b/dRm28teBlbCjgSH0767EQ0E'],
            'confie' => [
                'full' => 'https://payment.relocation-in-paris.fr/b/4gMaEZ9h1dKrcCr7zy7EQ0N',
                'deposit' => 'https://payment.relocation-in-paris.fr/b/aFa14p9h15dVfOD9HG7EQ0x',
            ],
        ],
        'en' => [
            'accompagne' => ['full' => 'https://payment.relocation-in-paris.fr/b/6oU9AVbp96hZbyn1ba7EQ0F'],
            'confie' => [
                'full' => 'https://payment.relocation-in-paris.fr/b/28EbJ3dxhbCjfODcTS7EQ0M',
                'deposit' => 'https://payment.relocation-in-paris.fr/b/6oU00ldxhfSzauj3ji7EQ0u',
            ],
        ],
    ];

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private DistrictStaticMapUrl $staticMap,
    ) {
    }

    public function send(ContactListItem $contact, bool $withPaymentLink = false, bool $withDeposit = false, ?User $closer = null): void
    {
        $locale = \in_array($contact->lang, ['fr', 'en'], true) ? $contact->lang : 'fr';
        $fr = 'fr' === $locale;

        ['from' => $from, 'replyTo' => $replyTo] = CloserSender::senderFor($closer);
        $email = (new TemplatedEmail())
            ->from($from)
            ->to((string) $contact->email)
            // Le nom d'expéditeur porte déjà la marque : le suffixe la
            // répétait au prix des caractères visibles sur mobile.
            ->subject($fr
                ? 'Votre projet logement à Paris : le récapitulatif'
                : 'Your housing project in Paris: the recap')
            ->htmlTemplate('emails/contact_recap.html.twig')
            ->context([
                'locale' => $locale,
                'contact' => $contact,
                'moveInLabel' => $this->moveInLabel($contact->projectMoveInAt, $locale),
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

        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        $this->mailer->send($email);
    }

    /**
     * Fuzzy move-in wording derived from the stored date (Paris time):
     * no date or less than a month away reads "as soon as possible",
     * otherwise early/mid/late + localized month and year (day 1-10,
     * 11-20, 21+).
     */
    public function moveInLabel(?\DateTimeImmutable $moveInAt, string $locale): string
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $moveIn = $moveInAt?->setTimezone($paris);
        $inLessThanAMonth = null === $moveIn
            || $moveIn < new \DateTimeImmutable('now', $paris)->modify('+1 month');

        if ($inLessThanAMonth) {
            return $this->translator->trans('contact.recapEmail.moveIn.asap', locale: $locale);
        }

        $key = match (true) {
            (int) $moveIn->format('j') <= 10 => 'contact.recapEmail.moveIn.early',
            (int) $moveIn->format('j') <= 20 => 'contact.recapEmail.moveIn.mid',
            default => 'contact.recapEmail.moveIn.late',
        };

        // Localized month name, never hardcoded.
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $paris, pattern: 'MMMM yyyy');

        return $this->translator->trans($key, ['%month%' => (string) $formatter->format($moveIn)], locale: $locale);
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
