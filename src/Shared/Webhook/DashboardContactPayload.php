<?php

namespace App\Shared\Webhook;

use App\Contact\Entity\Contact;

/**
 * Shape of a contact request as the Dashboard backoffice expects it
 * (POST /webhooks/rip/contact). Raw values only, never translated labels:
 * the help type is a stable slug, the offer its key.
 */
final class DashboardContactPayload
{
    private const HELP_TYPES = [
        'contact.contactForm.helpType.choice.1' => 'housing_search',
        'contact.contactForm.helpType.choice.2' => 'business',
        'contact.contactForm.helpType.choice.3' => 'rental_management',
        'contact.contactForm.helpType.choice.4' => 'other',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function fromContact(Contact $contact): array
    {
        return [
            'reference' => $contact->getReference(),
            'first_name' => $contact->getFirstName(),
            'last_name' => $contact->getLastName(),
            'email' => $contact->getEmail(),
            'phone' => $contact->getPhoneNumber(),
            'company' => $contact->getCompany(),
            'help_type' => self::helpTypeSlug($contact->getHelpType()),
            'offer' => $contact->getOffer(),
            'message' => $contact->getMessage(),
            'lang' => $contact->getLang(),
            'created_at' => $contact->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function helpTypeSlug(?string $translationKey): string
    {
        return self::HELP_TYPES[$translationKey] ?? 'other';
    }
}
