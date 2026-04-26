<?php

namespace App\PropertyEstimation\MessageHandler;

use App\Enum\EmailAddress;
use App\PropertyEstimation\Message\SendEstimationEmailMessage;
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
    ) {}

    public function __invoke(SendEstimationEmailMessage $message): void
    {
        $email = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to(EmailAddress::CONTACT->value)
            ->subject("🏠 Demande d'éstimation | Relocation In Paris")
            ->htmlTemplate('emails/property_estimation.html.twig')
            ->context([
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
            ]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Estimation email failed: ' . $e->getMessage(), ['email' => $message->email]);
        }
    }
}
