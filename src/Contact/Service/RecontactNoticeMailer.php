<?php

declare(strict_types=1);

namespace App\Contact\Service;

use App\Contact\Entity\Contact;
use App\Contact\Repository\ContactEventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Optional heads-up to the prospect when a recontact is planned: "our team
 * will get back to you on <date> via <channel>", in their language. Sent
 * only when the admin ticks the checkbox in the next-step editor; traced
 * in the follow-up thread.
 */
final readonly class RecontactNoticeMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private ContactEventRepository $events,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public function send(Contact $contact): void
    {
        $recallAt = $contact->getRecallAt();
        $clientEmail = (string) $contact->getEmail();
        if (null === $recallAt || false === filter_var($clientEmail, \FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $locale = \in_array($contact->getLang(), ['fr', 'en'], true) ? $contact->getLang() : 'fr';
        $fr = 'fr' === $locale;
        $channel = $contact->getRecontactChannel();
        $channelLabel = null !== $channel ? $this->translator->trans($channel->labelKey(), locale: $locale) : null;
        $agentName = VisioInvitationMailer::agentFirstName($contact);

        // Subject built around who + how + when ("Sarah vous rappelle mardi
        // 12 août à 14h00"): everything readable from the inbox list. No
        // emoji on client-facing subjects (premium tone, and they weigh
        // against deliverability).
        $dateText = \sprintf($fr ? '%s à %s' : '%s at %s', VisioInvitationMailer::humanDate($recallAt, $fr), $recallAt->format($fr ? 'H\hi' : 'H:i'));
        $who = $agentName ?? ($fr ? 'Notre équipe' : 'Our team');
        $subject = match ($channel?->value) {
            'phone' => $fr ? \sprintf('%s vous rappelle %s', $who, $dateText) : \sprintf('%s will call you %s', $who, $dateText),
            'whatsapp' => $fr ? \sprintf('%s vous écrit sur WhatsApp %s', $who, $dateText) : \sprintf('%s will message you on WhatsApp %s', $who, $dateText),
            'sms' => $fr ? \sprintf('%s vous envoie un SMS %s', $who, $dateText) : \sprintf('%s will text you %s', $who, $dateText),
            'email' => $fr ? \sprintf('%s vous écrit %s', $who, $dateText) : \sprintf('%s will email you %s', $who, $dateText),
            default => $fr ? \sprintf('Nous revenons vers vous %s', $dateText) : \sprintf('We will get back to you %s', $dateText),
        };

        // Sent from the closer's own address when it lives on the agency
        // domain (CloserSender rules, same as the visio invitation).
        ['from' => $from, 'replyTo' => $replyTo] = CloserSender::senderFor($contact->getAssignedTo());
        $email = (new TemplatedEmail())
            ->from($from)
            ->to($clientEmail)
            ->subject($subject)
            ->htmlTemplate('emails/contact_recontact_client.html.twig')
            ->context([
                'fr' => $fr,
                'firstName' => $contact->getFirstName(),
                'recallAt' => $recallAt,
                'channel' => $channel?->value,
                'channelLabel' => $channelLabel,
                'agentName' => $agentName,
            ]);
        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface|HandlerFailedException|\Symfony\Component\Mime\Exception\ExceptionInterface $e) {
            // An email failure must never break the business action.
            $this->logger->error('Recontact notice email failed: '.$e->getMessage(), [
                'reference' => $contact->getReference(),
            ]);

            return;
        }

        $this->events->recordKind(
            $contact,
            'recontact_notice',
            $recallAt->format('d.m.Y H\hi').(null !== $channelLabel ? ' · '.$channelLabel : ''),
        );
        $this->logger->info('Recontact notice sent to the prospect', [
            'reference' => $contact->getReference(),
            'recallAt' => $recallAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
