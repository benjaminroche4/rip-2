<?php

declare(strict_types=1);

namespace App\Contact\Service;

use App\Contact\Entity\Contact;
use App\Contact\Message\SendContactEmailsMessage;
use App\Shared\Webhook\DashboardContactPayload;
use App\Shared\Webhook\MakeWebhookTarget;
use App\Shared\Webhook\NotifyDashboardMessage;
use App\Shared\Webhook\NotifyMakeWebhookMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything that follows a persisted public contact request, whichever form
 * produced it (contact page or "On vous rappelle"): client + team emails, the
 * Make scenario and the Dashboard lead. Messenger stays sync (o2switch).
 */
final readonly class ContactNotifier
{
    public function __construct(
        private MessageBusInterface $bus,
        private TranslatorInterface $translator,
    ) {
    }

    public function notify(Contact $contact): void
    {
        $offer = $contact->getOffer();
        // The call-back form has no free text: its answers travel as a readable summary
        $message = $contact->getMessage() ?? $this->callbackSummary($contact);

        $this->bus->dispatch(new SendContactEmailsMessage(
            firstName: $contact->getFirstName(),
            lastName: $contact->getLastName(),
            email: $contact->getEmail(),
            phoneNumber: $contact->getPhoneNumber(),
            helpType: $contact->getHelpType(),
            message: $message,
            company: $contact->getCompany(),
            lang: $contact->getLang(),
            ip: $contact->getIp(),
            createdAt: $contact->getCreatedAt(),
            offer: $offer,
            callbackAnswers: $this->callbackAnswers($contact),
        ));

        $lang = $contact->getLang();
        $label = fn (?\BackedEnum $value): ?string => null === $value || !method_exists($value, 'labelKey')
            ? null
            : $this->translator->trans($value->labelKey(), locale: $lang);

        $this->bus->dispatch(new NotifyMakeWebhookMessage([
            'firstName' => $contact->getFirstName(),
            'lastName' => $contact->getLastName(),
            'email' => $contact->getEmail(),
            'phone' => $contact->getPhoneNumber(),
            'helpType' => $this->translator->trans($contact->getHelpType(), locale: $lang),
            'offer' => null !== $offer
                ? $this->translator->trans('contact.contactForm.offer.'.$offer.'.title', locale: $lang)
                : null,
            'message' => $message,
            'company' => $contact->getCompany(),
            'lang' => $lang,
            'createdAt' => $contact->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'source' => $contact->getSource(),
            'situation' => $label($contact->getSituation()),
            'settleTiming' => $label($contact->getSettleTiming()),
            'furnishing' => $label($contact->getFurnishing()),
            'rooms' => $label($contact->getRooms()),
            'rentBudget' => $label($contact->getRentBudget()),
            'guarantor' => $label($contact->getGuarantor()),
            'contactMethod' => $label($contact->getContactMethod()),
            'contactSlot' => $label($contact->getContactSlot()),
        ], MakeWebhookTarget::CONTACT));

        // The Dashboard contract is unchanged: call-back answers ride in the message
        $payload = DashboardContactPayload::fromContact($contact);
        $payload['message'] = $message;
        $this->bus->dispatch(new NotifyDashboardMessage($payload));
    }

    /**
     * Raw call-back answers (enum values) for the dedicated emails, null for the contact form.
     *
     * @return array<string, string>|null
     */
    private function callbackAnswers(Contact $contact): ?array
    {
        if (Contact::SOURCE_CALLBACK !== $contact->getSource()) {
            return null;
        }

        $answers = [
            'situation' => $contact->getSituation(),
            'settleTiming' => $contact->getSettleTiming(),
            'furnishing' => $contact->getFurnishing(),
            'rooms' => $contact->getRooms(),
            'rentBudget' => $contact->getRentBudget(),
            'guarantor' => $contact->getGuarantor(),
            'contactMethod' => $contact->getContactMethod(),
            'contactSlot' => $contact->getContactSlot(),
        ];

        return array_map(static fn (\BackedEnum $value): string => (string) $value->value, array_filter($answers));
    }

    /**
     * Human-readable recap of the call-back answers, in the visitor's language
     * (null for the contact form).
     */
    public function callbackSummary(Contact $contact): ?string
    {
        if (Contact::SOURCE_CALLBACK !== $contact->getSource()) {
            return null;
        }

        $lang = $contact->getLang();
        $lines = [$this->translator->trans('callback.summary.title', locale: $lang)];
        foreach ([
            'situation' => $contact->getSituation(),
            'settleTiming' => $contact->getSettleTiming(),
            'furnishing' => $contact->getFurnishing(),
            'rooms' => $contact->getRooms(),
            'rentBudget' => $contact->getRentBudget(),
            'guarantor' => $contact->getGuarantor(),
            'contactMethod' => $contact->getContactMethod(),
            'contactSlot' => $contact->getContactSlot(),
        ] as $field => $value) {
            if (null !== $value) {
                $lines[] = \sprintf(
                    '%s %s',
                    $this->translator->trans('callback.summary.'.$field, locale: $lang),
                    $this->translator->trans($value->labelKey(), locale: $lang),
                );
            }
        }

        return implode("\n", $lines);
    }


}
