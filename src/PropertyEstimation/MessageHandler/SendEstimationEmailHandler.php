<?php

namespace App\PropertyEstimation\MessageHandler;

use App\PropertyEstimation\Message\SendEstimationEmailMessage;
use App\Shared\Email\EmailAddress;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendEstimationEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendEstimationEmailMessage $message): void
    {
        $context = [
            'address' => $message->address,
            'propertyCondition' => $message->propertyCondition,
            'surface' => $message->surface,
            'bathroom' => $message->bathroom,
            'bedroom' => $message->bedroom,
            'emailLead' => $message->email,
            'phoneNumber' => $message->phoneNumber,
            'createdAt' => $message->createdAt,
            'lang' => $message->lang,
            'ip' => $message->ip,
        ];

        $adminEmail = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to(EmailAddress::CONTACT->value)
            ->subject("🏠 Demande d'éstimation | Relocation In Paris")
            ->htmlTemplate('emails/property_estimation.html.twig')
            ->context($context);

        $clientSubject = 'fr' === $message->lang
            ? "C'est noté, un expert vous rappelle sous 2h"
            : 'Got it, an expert will call you back within 2h';

        $clientContext = $context;
        unset($clientContext['ip']);

        $clientEmail = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to($message->email ?? EmailAddress::CONTACT->value)
            ->subject($clientSubject)
            ->htmlTemplate('emails/property_estimation_client.html.twig')
            ->context($clientContext);

        try {
            $this->mailer->send($adminEmail);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Estimation admin email failed: '.$e->getMessage(), ['email' => $message->email]);
        }

        if (null !== $message->email && '' !== $message->email) {
            try {
                $this->mailer->send($clientEmail);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Estimation client email failed: '.$e->getMessage(), ['email' => $message->email]);
            }
        }
    }
}
