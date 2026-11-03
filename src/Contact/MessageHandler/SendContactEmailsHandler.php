<?php

namespace App\Contact\MessageHandler;

use App\Contact\Message\SendContactEmailsMessage;
use App\Shared\Email\EmailAddress;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendContactEmailsHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendContactEmailsMessage $message): void
    {
        $context = [
            'firstName' => $message->firstName,
            'lastName' => $message->lastName,
            'emailContact' => $message->email,
            'phoneNumber' => $message->phoneNumber,
            'helpType' => $message->helpType,
            'offer' => $message->offer,
            'message' => $message->message,
            'company' => $message->company,
            'createdAt' => $message->createdAt,
            'lang' => $message->lang,
            'ip' => $message->ip,
            'callbackAnswers' => $message->callbackAnswers ?? [],
            // Call-back form: a chosen moment of the day replaces the "30 minutes" promise (none or asap keeps it)
            'contactSlot' => 'asap' === ($message->callbackAnswers['contactSlot'] ?? null) ? null : ($message->callbackAnswers['contactSlot'] ?? null),
            'contactMethod' => $message->callbackAnswers['contactMethod'] ?? null,
        ];
        $isCallback = null !== $message->callbackAnswers;

        $adminEmail = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to(EmailAddress::CONTACT->value)
            ->subject($isCallback ? '📞 Demande de rappel | Relocation In Paris' : '📩 Demande de contact | Relocation In Paris')
            ->htmlTemplate($isCallback ? 'emails/callback_admin.html.twig' : 'emails/contact_admin.html.twig')
            ->context($context);

        $clientSubject = match (true) {
            null !== $context['contactSlot'] && 'fr' === $message->lang => sprintf('%s, votre demande de rappel est confirmée', $message->firstName ?? ''),
            null !== $context['contactSlot'] => sprintf('%s, your call-back request is confirmed', $message->firstName ?? ''),
            'fr' === $message->lang => sprintf('%s, votre conseiller vous contacte sous 30 minutes', $message->firstName ?? ''),
            default => sprintf('%s, your advisor will contact you within 30 minutes', $message->firstName ?? ''),
        };

        $clientContext = $context;
        unset($clientContext['ip']);

        $clientEmail = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to($message->email ?? EmailAddress::CONTACT->value)
            ->subject($clientSubject)
            ->htmlTemplate($isCallback ? 'emails/callback_client.html.twig' : 'emails/contact_client.html.twig')
            ->context($clientContext);

        try {
            $this->mailer->send($adminEmail);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Contact admin email failed: '.$e->getMessage(), ['email' => $message->email]);
        }

        if (null !== $message->email && '' !== $message->email) {
            try {
                $this->mailer->send($clientEmail);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Contact client email failed: '.$e->getMessage(), ['email' => $message->email]);
            }
        }
    }
}
