<?php

namespace App\PropertyListing\MessageHandler;

use App\PropertyListing\Message\SendListingEmailMessage;
use App\PropertyListing\Service\ListingPhotoStorage;
use App\Shared\Email\EmailAddress;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class SendListingEmailHandler
{
    // Shared-hosting SMTP caps message size around 25 MB: attach photos to
    // the admin email up to this budget, the rest stays in the referenced
    // var/uploads folder.
    private const MAX_ATTACHMENTS_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly ListingPhotoStorage $photoStorage,
    ) {
    }

    public function __invoke(SendListingEmailMessage $message): void
    {
        $context = [
            'fullName' => $message->fullName,
            'emailLead' => $message->email,
            'phoneNumber' => $message->phoneNumber,
            'address' => $message->address,
            'propertyType' => $message->propertyType,
            'propertyStatus' => $message->propertyStatus,
            'bedrooms' => $message->bedrooms,
            'bathrooms' => $message->bathrooms,
            'surface' => $message->surface,
            'floor' => $message->floor,
            'buildingFloors' => $message->buildingFloors,
            'furnishing' => $message->furnishing,
            'orientations' => $message->orientations,
            'leaseTypes' => $message->leaseTypes,
            'rent' => $message->rent,
            'charges' => $message->charges,
            'deposit' => $message->deposit,
            'amenities' => $message->amenities,
            'note' => $message->note,
            'photosCount' => $message->photosCount,
            'lang' => $message->lang,
            'ip' => $message->ip,
            'createdAt' => $message->createdAt,
        ];

        $from = new Address(EmailAddress::CONTACT->value, 'Relocation In Paris');

        $adminEmail = (new TemplatedEmail())
            ->from($from)
            ->to(EmailAddress::CONTACT->value)
            ->replyTo($message->email)
            ->subject($this->adminSubject($message))
            ->htmlTemplate('emails/property_listing.html.twig')
            ->context($context);

        // The owner's confirmation stays light: photos only travel on the
        // admin email.
        if (null !== $message->photosFolder) {
            $budget = self::MAX_ATTACHMENTS_BYTES;
            foreach ($this->photoStorage->photoPaths($message->photosFolder) as $path) {
                $size = filesize($path) ?: 0;
                if ($size > $budget) {
                    continue;
                }
                $budget -= $size;
                $adminEmail->attachFromPath($path);
            }
        }

        // Internal-only details never reach the owner's inbox.
        $clientContext = $context;
        unset($clientContext['ip']);

        $clientEmail = (new TemplatedEmail())
            ->from($from)
            ->to($message->email)
            ->subject($this->translator->trans('listProperty.email.client.subject', [], null, $message->lang))
            ->htmlTemplate('emails/property_listing_client.html.twig')
            ->context($clientContext);

        try {
            $this->mailer->send($adminEmail);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Listing admin email failed: '.$e->getMessage(), ['email' => $message->email]);
        }

        try {
            $this->mailer->send($clientEmail);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Listing client email failed: '.$e->getMessage(), ['email' => $message->email]);
        }
    }

    /**
     * "🏡 Nouveau bien proposé (45 m², 11e) | Relocation In Paris": the two
     * most useful facts (surface, arrondissement) inside the fixed subject.
     */
    private function adminSubject(SendListingEmailMessage $message): string
    {
        $details = [$message->surface.' m²'];

        if (1 === preg_match('/\b75(\d{3})\b/', $message->address, $match)) {
            $arrondissement = (int) substr($match[1], -2);
            if ($arrondissement >= 1 && $arrondissement <= 20) {
                $details[] = 1 === $arrondissement ? '1er' : $arrondissement.'e';
            }
        }

        return $this->translator->trans(
            'listProperty.email.admin.subject',
            ['%details%' => implode(', ', $details)],
            null,
            'fr',
        );
    }
}
