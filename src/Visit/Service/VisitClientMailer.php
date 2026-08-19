<?php

declare(strict_types=1);

namespace App\Visit\Service;

use App\Shared\Email\EmailAddress;
use App\Visit\Entity\Visit;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Confirmation email sent to every reachable contact of the dossier when a
 * visit is booked with "notify the client" ticked: date and time
 * (Europe/Paris), address, estimated duration, and who performs the visit.
 * Each recipient gets it in their own contact language, duplicate
 * addresses only once. Best effort by design: no reachable contact means
 * no email, and a transport failure is logged as a warning without ever
 * blocking the booking (the visit already exists).
 */
final readonly class VisitClientMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public function send(Visit $visit): void
    {
        $dossier = $visit->getDossier();
        $scheduledAt = $visit->getScheduledAt();
        if (null === $dossier || null === $scheduledAt) {
            return;
        }

        foreach ($this->recipients($dossier) as $recipient) {
            $this->sendTo($recipient, $visit, $dossier, $scheduledAt);
        }
    }

    /**
     * Emails the post-visit client note to every reachable dossier contact,
     * each in their own language (same mechanics as the confirmation email:
     * deduplicated addresses, best-effort transport). Returns the number of
     * emails actually handed to the mailer alongside the number of
     * reachable contacts, so the caller can tell a partial send (transport
     * failure for some) from a full one.
     *
     * @return array{sent: int, total: int}
     */
    public function sendClientNote(Visit $visit): array
    {
        $dossier = $visit->getDossier();
        $scheduledAt = $visit->getScheduledAt();
        $note = trim((string) $visit->getClientNote());
        if (null === $dossier || null === $scheduledAt || '' === $note) {
            return ['sent' => 0, 'total' => 0];
        }

        $recipients = $this->recipients($dossier);
        $sent = 0;
        foreach ($recipients as $recipient) {
            if ($this->sendNoteTo($recipient, $visit, $scheduledAt, $note)) {
                ++$sent;
            }
        }

        return ['sent' => $sent, 'total' => \count($recipients)];
    }

    /**
     * Tous les contacts du dossier joignables par email (une demande
     * d'entreprise suit plusieurs personnes), chacun dans sa langue. Les
     * adresses en double ne partent qu'une fois.
     *
     * @return array<string, \App\Dossier\Entity\DossierPerson>
     */
    private function recipients(\App\Dossier\Entity\Dossier $dossier): array
    {
        $recipients = [];
        foreach ($dossier->getPersons() as $person) {
            $address = trim((string) $person->getEmail());
            if (false === filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $recipients[mb_strtolower($address)] ??= $person;
        }

        return $recipients;
    }

    private function sendNoteTo(\App\Dossier\Entity\DossierPerson $recipient, Visit $visit, \DateTimeImmutable $scheduledAt, string $note): bool
    {
        $address = trim((string) $recipient->getEmail());
        $locale = $recipient->getLanguage()->value ?? 'fr';
        $fr = 'fr' === $locale;

        $dateFormatter = new \IntlDateFormatter(
            $fr ? 'fr_FR' : 'en_GB',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'Europe/Paris',
            pattern: 'EEEE d MMMM yyyy',
        );
        $dateText = (string) $dateFormatter->format($scheduledAt);
        $timeText = $scheduledAt->format('H:i');

        $email = (new TemplatedEmail())
            ->from(new Address(EmailAddress::CONTACT->value, 'Relocation in Paris'))
            ->to($address)
            ->subject($fr
                ? \sprintf('Le point sur votre visite du %s', $dateText)
                : \sprintf('Following up on your visit of %s', $dateText))
            ->htmlTemplate('emails/visit_client_note.html.twig')
            ->context([
                'fr' => $fr,
                'recipientFirstName' => trim((string) $recipient->getFirstName()),
                'note' => $note,
                'dateText' => $dateText,
                'timeText' => $timeText,
                'visitAddress' => trim((string) $visit->getAddress()),
            ]);

        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Visit client note email could not be sent.', [
                'visit' => (string) $visit->getReference(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sendTo(\App\Dossier\Entity\DossierPerson $recipient, Visit $visit, \App\Dossier\Entity\Dossier $dossier, \DateTimeImmutable $scheduledAt): void
    {
        $address = trim((string) $recipient->getEmail());
        $locale = $recipient->getLanguage()->value ?? 'fr';
        $fr = 'fr' === $locale;

        // Date longue dans la langue du destinataire; l'heure reste au
        // format 24h des deux côtés (heure de Paris, comme le stockage).
        $dateFormatter = new \IntlDateFormatter(
            $fr ? 'fr_FR' : 'en_GB',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'Europe/Paris',
            pattern: 'EEEE d MMMM yyyy',
        );
        $dateText = (string) $dateFormatter->format($scheduledAt);
        $timeText = $scheduledAt->format('H:i');

        // Formule Accompagné sur une visite de bien : le client visite pour
        // son propre compte, l'email ne parle d'aucun accompagnant d'équipe.
        $clientVisitsAlone = 'accompagne' === $dossier->getOffer()
            && \App\Visit\Domain\VisitType::PropertyVisit === $visit->getType();
        $assignee = $visit->getAssignee();
        $assigneeName = null !== $assignee
            ? (trim(($assignee->getFirstName() ?? '').' '.($assignee->getLastName() ?? '')) ?: (string) $assignee->getEmail())
            : null;

        $email = (new TemplatedEmail())
            ->from(new Address(EmailAddress::CONTACT->value, 'Relocation in Paris'))
            ->to($address)
            ->subject($fr
                ? \sprintf('Votre visite du %s à %s', $dateText, $timeText)
                : \sprintf('Your visit on %s at %s', $dateText, $timeText))
            ->htmlTemplate('emails/visit_client_confirmation.html.twig')
            ->context([
                'fr' => $fr,
                'recipientFirstName' => trim((string) $recipient->getFirstName()),
                'dateText' => $dateText,
                'timeText' => $timeText,
                'visitAddress' => trim((string) $visit->getAddress()),
                'durationMinutes' => $visit->getDurationMinutes(),
                'typeLabel' => $this->translator->trans($visit->getType()->labelKey(), locale: $locale),
                'clientVisitsAlone' => $clientVisitsAlone,
                'assigneeName' => $clientVisitsAlone ? null : $assigneeName,
                'clientPresent' => $visit->isClientPresent(),
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->warning('Visit confirmation email could not be sent.', [
                'visit' => (string) $visit->getReference(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
