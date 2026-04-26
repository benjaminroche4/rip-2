<?php

namespace App\MessageHandler;

use App\Enum\EmailAddress;
use App\Message\SendContactEmailsMessage;
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
    ) {}

    public function __invoke(SendContactEmailsMessage $message): void
    {
        $context = [
            'firstName' => $message->firstName,
            'lastName' => $message->lastName,
            'emailContact' => $message->email,
            'phoneNumber' => $message->phoneNumber,
            'helpType' => $message->helpType,
            'message' => $message->message,
            'company' => $message->company,
            'createdAt' => $message->createdAt,
            'lang' => $message->lang,
            'ip' => $message->ip,
        ];

        $adminEmail = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to(EmailAddress::CONTACT->value)
            ->subject('📩 Demande de contact | Relocation In Paris')
            ->htmlTemplate('emails/contact_admin.html.twig')
            ->context($context);

        $clientSubject = $message->lang === 'fr'
            ? sprintf('%s, votre conseiller vous contacte sous 30 minutes', $message->firstName ?? '')
            : sprintf('%s, your advisor will contact you within 30 minutes', $message->firstName ?? '');

        $clientContext = $context;
        unset($clientContext['ip']);

        $clientEmail = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to($message->email ?? EmailAddress::CONTACT->value)
            ->subject($clientSubject)
            ->htmlTemplate('emails/contact_client.html.twig')
            ->context($clientContext);

        try {
            $this->mailer->send($adminEmail);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Contact admin email failed: ' . $e->getMessage(), ['email' => $message->email]);
        }

        if ($message->email !== null && $message->email !== '') {
            try {
                $this->mailer->send($clientEmail);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Contact client email failed: ' . $e->getMessage(), ['email' => $message->email]);
            }
        }
    }
}
