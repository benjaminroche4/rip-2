<?php

declare(strict_types=1);

namespace App\Contact\Service;

use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\NextStep;
use App\Contact\Entity\Contact;
use App\Contact\Repository\ContactEventRepository;
use App\Shared\Email\EmailAddress;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\CalendarLink\CalendarEvent;
use Symfony\UX\CalendarLink\Registry\CalendarLinkProviderRegistry;

/**
 * When a video call is planned on a lead, invites both sides by email with
 * an iCalendar attachment (METHOD:REQUEST): the prospect gets a confirmation
 * in their language, the assignee (plus the agency inbox) gets the invite
 * that calendar clients push straight into their agenda. Re-saving the
 * date re-sends the invite with the same UID and a higher SEQUENCE, so
 * calendars update the existing event instead of duplicating it.
 *
 * The Google event is created in the assigned closer's own agenda (they
 * organize the meeting) and the confirmation email is sent from their
 * address; without an assigned closer on the agency domain, everything
 * falls back to the central contact address. The word "visio" never
 * reaches the client: emails, event titles and the ICS fallback all say
 * "appel vidéo" / "video call" (internal admin emails keep the jargon).
 */
final readonly class VisioInvitationMailer
{
    private const DURATION_MINUTES = 20;

    public function __construct(
        private MailerInterface $mailer,
        private GoogleCalendarClient $calendar,
        private ContactEventRepository $events,
        private CalendarLinkProviderRegistry $calendarLinks,
        private EntityManagerInterface $em,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        #[Autowire('%admin_path_prefix%')]
        private string $adminPathPrefix,
    ) {
    }

    public function send(Contact $contact, bool $rescheduled = false): void
    {
        $visioAt = $contact->getRecallAt();
        $clientEmail = (string) $contact->getEmail();
        if (null === $visioAt || false === filter_var($clientEmail, \FILTER_VALIDATE_EMAIL)) {
            // No valid client address: no invite (the agenda event, when
            // possible, is still handled by the caller's flow).
            return;
        }

        $locale = \in_array($contact->getLang(), ['fr', 'en'], true) ? $contact->getLang() : 'fr';
        $fr = 'fr' === $locale;
        $fullName = trim(((string) $contact->getFirstName()).' '.((string) $contact->getLastName()));
        $clientName = '' !== $fullName ? $fullName : $clientEmail;
        $assigneeEmail = $contact->getAssignedTo()?->getEmail();

        // Google Calendar first: event in the closer's own agenda (central
        // fallback without one) + auto Meet link, kept stable across
        // reschedules. Falls back to a plain iCalendar invite when the API
        // is unavailable.
        $meetLink = null;
        $ics = null;
        $organizer = $this->organizerFor($contact);
        $event = $this->calendar->upsertVisioEvent(
            $contact->getVisioEventId(),
            self::eventTitle($contact, $fr, $clientName),
            \sprintf('%s / %s%s', $clientName, $clientEmail, null !== $contact->getPhoneNumber() ? ' / '.$contact->getPhoneNumber() : ''),
            $visioAt,
            $visioAt->modify(\sprintf('+%d minutes', self::DURATION_MINUTES)),
            array_values(array_unique(array_filter([$clientEmail, $assigneeEmail]))),
            impersonate: $organizer,
        );
        if (null !== $event) {
            $meetLink = $event['meetLink'];
            $contact->setVisioEventId($event['eventId'])
                ->setVisioMeetLink($meetLink);
            $this->em->flush();
        }
        // The invite is always attached: non-Google mail clients (Outlook,
        // Apple Mail) have no other way to add the meeting. With a Google
        // event, the UID matches its iCalUID so calendars merge instead of
        // duplicating; without one, the stable fallback UID applies.
        $ics = $this->buildIcs($contact, $visioAt, $clientName, $clientEmail, $assigneeEmail, $fr, organizerEmail: $organizer, meetLink: $meetLink);

        try {
            $mode = $rescheduled ? 'rescheduled' : 'scheduled';
            // Human date up front ("mardi 12 août à 14h00"): the essential
            // info must be readable from the inbox list alone.
            $dateText = \sprintf($fr ? '%s à %s' : '%s at %s', self::humanDate($visioAt, $fr), $visioAt->format($fr ? 'H\hi' : 'H:i'));
            $agentName = self::agentFirstName($contact);
            ['from' => $from, 'replyTo' => $replyTo] = $this->clientSender($contact);
            $client = (new TemplatedEmail())
                ->from($from)
                ->to($clientEmail)
                // Aucun emoji dans les objets adressés au client : la marque
                // se joue premium, et les filtres promotions les pénalisent.
                ->subject(match ([$fr, $rescheduled]) {
                    [true, false] => \sprintf('Votre appel vidéo est confirmé : %s', $dateText),
                    [true, true] => \sprintf('Votre appel vidéo est déplacé au %s', $dateText),
                    [false, false] => \sprintf('Your video call is confirmed: %s', $dateText),
                    default => \sprintf('Your video call moved to %s', $dateText),
                })
                ->htmlTemplate('emails/contact_visio_client.html.twig')
                ->context([
                    'fr' => $fr,
                    'firstName' => $contact->getFirstName(),
                    'visioAt' => $visioAt,
                    'meetLink' => $meetLink,
                    'mode' => $mode,
                    'agentName' => $agentName,
                    'durationMinutes' => self::DURATION_MINUTES,
                    'addToCalendarLinks' => $this->addToCalendarLinks($contact, $clientName, $fr, $visioAt, $meetLink),
                ]);
            if (null !== $replyTo) {
                $client->replyTo($replyTo);
            }
            $client->addPart(new DataPart($ics, 'invitation.ics', 'text/calendar'));

            $recipients = [EmailAddress::CONTACT->value];
            if (null !== $assigneeEmail && '' !== $assigneeEmail && EmailAddress::CONTACT->value !== $assigneeEmail) {
                $recipients[] = $assigneeEmail;
            }
            $agent = (new TemplatedEmail())
                ->from('Contact <contact@relocation-in-paris.fr>')
                ->to(...$recipients)
                ->subject(\sprintf('📹 Visio %s | %s le %s', $rescheduled ? 'déplacée' : 'planifiée', $clientName, $visioAt->format('d.m à H\hi')))
                ->htmlTemplate('emails/contact_visio_agent.html.twig')
                ->context([
                    'mode' => $mode,
                    'visioAt' => $visioAt,
                    'firstName' => $contact->getFirstName(),
                    'lastName' => $contact->getLastName(),
                    'emailContact' => $contact->getEmail(),
                    'phoneNumber' => $contact->getPhoneNumber(),
                    'lang' => $contact->getLang(),
                    'reference' => $contact->getReference(),
                    'meetLink' => $meetLink,
                    'detailUrl' => $this->urlGenerator->generate('admin_contact_show', [
                        '_locale' => 'fr',
                        'adminPrefix' => $this->adminPathPrefix,
                        'reference' => $contact->getReference(),
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                ]);
            $agent->addPart(new DataPart($ics, 'visio.ics', 'text/calendar'));

            $this->mailer->send($client);
            $this->mailer->send($agent);
        } catch (TransportExceptionInterface|HandlerFailedException|\Symfony\Component\Mime\Exception\ExceptionInterface $e) {
            // An email failure must never break the business action.
            $this->logger->error('Visio invitation email failed: '.$e->getMessage(), [
                'reference' => $contact->getReference(),
            ]);
        }

        // Follow-up thread trace + business log.
        $detail = $visioAt->format('d.m.Y H\hi');
        $this->events->recordKind($contact, $rescheduled ? 'visio_rescheduled' : 'visio_planned', $detail);
        $this->logger->info(\sprintf('Visio %s', $rescheduled ? 'rescheduled' : 'planned'), [
            'reference' => $contact->getReference(),
            'visioAt' => $visioAt->format(\DateTimeInterface::ATOM),
            'googleEvent' => $contact->getVisioEventId(),
            'meetLink' => $meetLink,
        ]);
    }

    /**
     * Status-change side effects on a planned visio, in one place for
     * every caller (detail card, list quick actions): closed notifies the
     * cancellation, a rollback to "new" cleans up silently, a conversion
     * keeps the meeting and traces it.
     */
    public function onStatusChange(Contact $contact, ContactStatus $newStatus): void
    {
        if (ContactStatus::InProgress === $newStatus) {
            return;
        }

        if (ContactStatus::Converted === $newStatus) {
            $visioAt = NextStep::Visio === $contact->getNextStep() ? $contact->getRecallAt() : null;
            if (null !== $visioAt) {
                $this->events->recordKind($contact, 'visio_kept', $visioAt->format('d.m.Y H\hi'));
                $this->logger->info('Visio kept through conversion', [
                    'reference' => $contact->getReference(),
                    'visioAt' => $visioAt->format(\DateTimeInterface::ATOM),
                ]);
            }

            return;
        }

        $this->cancel($contact, notify: ContactStatus::Closed === $newStatus);
    }

    /**
     * Re-syncs the calendar event attendees and title after a reassignment:
     * the new assignee gets the meeting in their agenda, the old one loses
     * it. Emails are not re-sent (the meeting itself did not change). The
     * event stays in its original organizer's agenda so the Meet link and
     * the UID clients already received survive the reassignment.
     */
    public function refreshAttendees(Contact $contact): void
    {
        $visioAt = $contact->getRecallAt();
        $eventId = $contact->getVisioEventId();
        $clientEmail = (string) $contact->getEmail();
        if (null === $visioAt || null === $eventId || '' === $clientEmail) {
            return;
        }

        $fr = 'en' !== $contact->getLang();
        $fullName = trim(((string) $contact->getFirstName()).' '.((string) $contact->getLastName()));
        $clientName = '' !== $fullName ? $fullName : $clientEmail;
        $assigneeEmail = $contact->getAssignedTo()?->getEmail();
        $event = $this->calendar->upsertVisioEvent(
            $eventId,
            self::eventTitle($contact, $fr, $clientName),
            \sprintf('%s / %s%s', $clientName, $clientEmail, null !== $contact->getPhoneNumber() ? ' / '.$contact->getPhoneNumber() : ''),
            $visioAt,
            $visioAt->modify(\sprintf('+%d minutes', self::DURATION_MINUTES)),
            array_values(array_unique(array_filter([$clientEmail, $assigneeEmail]))),
            impersonate: $this->organizerFor($contact),
        );
        if (null !== $event) {
            $contact->setVisioEventId($event['eventId'])
                ->setVisioMeetLink($event['meetLink']);
            $this->em->flush();
            $this->logger->info('Visio attendees re-synced after reassignment', [
                'reference' => $contact->getReference(),
                'googleEvent' => $event['eventId'],
            ]);
        }
    }

    /**
     * "mardi 12 août" / "Tuesday 12 August", pinned to Paris time: the
     * subject line carries the essential info on its own.
     */
    public static function humanDate(\DateTimeImmutable $date, bool $fr): string
    {
        $formatter = new \IntlDateFormatter(
            $fr ? 'fr_FR' : 'en_GB',
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            'Europe/Paris',
            pattern: 'EEEE d MMMM',
        );

        return (string) $formatter->format($date);
    }

    /**
     * First name of the assigned agent, the personal touch of the client
     * emails; null when the lead is unassigned (templates fall back to
     * "our team").
     */
    public static function agentFirstName(Contact $contact): ?string
    {
        $firstName = trim((string) $contact->getAssignedTo()?->getFirstName());

        return '' !== $firstName ? $firstName : null;
    }

    /**
     * Client-facing meeting title: "{client} • {closer} - Votre nouvel
     * appartement à Paris" (client locale, simple dash, closer part
     * omitted when the lead is unassigned). Used for the Google event,
     * the ICS fallback and the add-to-calendar links.
     */
    public static function eventTitle(Contact $contact, bool $fr, string $clientName): string
    {
        $clientFirst = trim((string) $contact->getFirstName());
        $clientFirst = '' !== $clientFirst ? $clientFirst : $clientName;
        $closerFirst = self::agentFirstName($contact);
        $who = null !== $closerFirst ? \sprintf('%s • %s', $clientFirst, $closerFirst) : $clientFirst;

        return \sprintf('%s - %s', $who, $fr ? 'Votre nouvel appartement à Paris' : 'Your new Home in Paris');
    }

    /**
     * How the meeting is named in client-facing prose: "appel vidéo avec
     * {prénom du closer}" / "video call with {first name}", without the
     * closer part when the lead is unassigned. The word "visio" is banned
     * from everything the client sees.
     */
    private static function videoCallPhrase(Contact $contact, bool $fr): string
    {
        $closerFirst = self::agentFirstName($contact);
        if ($fr) {
            return null !== $closerFirst ? 'appel vidéo avec '.$closerFirst : 'appel vidéo';
        }

        return null !== $closerFirst ? 'video call with '.$closerFirst : 'video call';
    }

    /**
     * The assignee's email when it can act as organizer and sender, i.e. a
     * valid address on the agency domain (the only one the Workspace
     * delegation can impersonate and the transactional sender verifies);
     * null otherwise.
     */
    private static function closerWorkspaceEmail(Contact $contact): ?string
    {
        return CloserSender::workspaceEmail($contact->getAssignedTo());
    }

    /**
     * Which agenda owns (or should own) the Google event. New event: the
     * assigned closer's agenda (null, i.e. the central organizer, without
     * one). Existing event: the actual organizer read from the API, so a
     * reassigned or legacy lead keeps patching and cancelling the event
     * where it really lives instead of orphaning it.
     */
    private function organizerFor(Contact $contact): ?string
    {
        $closerEmail = self::closerWorkspaceEmail($contact);
        $eventId = $contact->getVisioEventId();
        if (null === $eventId) {
            return $closerEmail;
        }

        // The event lives in the closer's agenda (new events) or in the
        // central one (legacy events, or leads without a closer): probe the
        // closer's copy first, then the central agenda. contact@ is not an
        // attendee anymore (its calendar must stay uncluttered), but legacy
        // events still resolve through either probe.
        $subjects = null !== $closerEmail ? [$closerEmail, null] : [null];
        foreach ($subjects as $subject) {
            $event = $this->calendar->getEvent($eventId, $subject);
            if (null === $event) {
                continue;
            }
            $organizer = $event['organizer']['email'] ?? null;

            return \is_string($organizer) && '' !== $organizer ? $organizer : $closerEmail;
        }

        return $closerEmail;
    }

    /**
     * From / Reply-To of the client-facing emails: the assigned closer's
     * own address and name when it lives on the agency domain (verified
     * sender), the central address otherwise, with the closer kept
     * reachable through Reply-To when their address is off-domain.
     *
     * @return array{from: Address, replyTo: ?Address}
     */
    private function clientSender(Contact $contact): array
    {
        return CloserSender::senderFor($contact->getAssignedTo());
    }

    /**
     * "Add to calendar" links for the client email (Google / Outlook), a
     * complement to the attached invite for prospects who never open
     * attachments. Empty on failure: links must never block the send.
     *
     * @return array<string, string> label => url
     */
    private function addToCalendarLinks(Contact $contact, string $clientName, bool $fr, \DateTimeImmutable $visioAt, ?string $meetLink): array
    {
        try {
            $phrase = self::videoCallPhrase($contact, $fr);
            $event = new CalendarEvent(
                title: self::eventTitle($contact, $fr, $clientName),
                start: $visioAt,
                end: $visioAt->modify(\sprintf('+%d minutes', self::DURATION_MINUTES)),
                description: \sprintf(
                    '%s, Relocation in Paris.%s',
                    ucfirst($phrase),
                    null !== $meetLink ? ($fr ? ' Rejoindre : ' : ' Join: ').$meetLink : '',
                ),
                url: $meetLink,
            );

            $links = [];
            foreach (['google', 'outlook'] as $provider) {
                $link = $this->calendarLinks->generate($event, $provider);
                $links[$link->label] = $link->url;
            }

            return $links;
        } catch (\Throwable $e) {
            $this->logger->warning('Add-to-calendar links generation failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Drops the planned visio from the agenda when it no longer applies
     * (step changed, lead closed...). With $notify, both sides get a
     * cancellation email; without (administrative rollback to "new"),
     * the agendas are cleaned silently. Call it BEFORE clearing the step
     * and date: the emails need them.
     */
    public function cancel(Contact $contact, bool $notify = true): void
    {
        // A stale recallAt can belong to a recall or a quote follow-up:
        // only a visio next step means a meeting actually exists.
        $visioAt = NextStep::Visio === $contact->getNextStep() ? $contact->getRecallAt() : null;
        $eventId = $contact->getVisioEventId();
        $organizer = $this->organizerFor($contact);

        if (null !== $eventId) {
            // Deleted under its organizer's identity: an attendee-side
            // deletion would only decline, not cancel the meeting.
            $this->calendar->deleteEvent($eventId, $organizer);
            $contact->setVisioEventId(null)
                ->setVisioMeetLink(null);
            $this->em->flush();
        }

        if (null !== $visioAt) {
            $this->events->recordKind($contact, 'visio_cancelled', $visioAt->format('d.m.Y H\hi'));
            $this->logger->info('Visio cancelled', [
                'reference' => $contact->getReference(),
                'visioAt' => $visioAt->format(\DateTimeInterface::ATOM),
                'notified' => $notify,
            ]);
        }

        $clientEmail = (string) $contact->getEmail();
        if (!$notify || null === $visioAt || false === filter_var($clientEmail, \FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $locale = \in_array($contact->getLang(), ['fr', 'en'], true) ? $contact->getLang() : 'fr';
        $fr = 'fr' === $locale;
        $fullName = trim(((string) $contact->getFirstName()).' '.((string) $contact->getLastName()));
        $clientName = '' !== $fullName ? $fullName : $clientEmail;
        $assigneeEmail = $contact->getAssignedTo()?->getEmail();

        // METHOD:CANCEL with the matching UID removes the meeting from
        // non-Google calendars too (the Google event itself was already
        // deleted API-side). Sent in every case: an invite ICS may have
        // reached the prospect at any earlier point.
        $ics = $this->buildIcs($contact, $visioAt, $clientName, $clientEmail, $assigneeEmail, $fr, organizerEmail: $organizer, cancelled: true, googleEventId: $eventId);

        try {
            $dateText = \sprintf($fr ? '%s à %s' : '%s at %s', self::humanDate($visioAt, $fr), $visioAt->format($fr ? 'H\hi' : 'H:i'));
            ['from' => $from, 'replyTo' => $replyTo] = $this->clientSender($contact);
            $client = (new TemplatedEmail())
                ->from($from)
                ->to($clientEmail)
                ->subject($fr
                    ? \sprintf('Votre appel vidéo du %s est annulé', $dateText)
                    : \sprintf('Your video call on %s is cancelled', $dateText))
                ->htmlTemplate('emails/contact_visio_client.html.twig')
                ->context([
                    'fr' => $fr,
                    'firstName' => $contact->getFirstName(),
                    'visioAt' => $visioAt,
                    'meetLink' => null,
                    'mode' => 'cancelled',
                    'agentName' => self::agentFirstName($contact),
                    'durationMinutes' => self::DURATION_MINUTES,
                ]);
            if (null !== $replyTo) {
                $client->replyTo($replyTo);
            }
            $client->addPart(new DataPart($ics, 'invitation.ics', 'text/calendar'));

            $recipients = [EmailAddress::CONTACT->value];
            if (null !== $assigneeEmail && '' !== $assigneeEmail && EmailAddress::CONTACT->value !== $assigneeEmail) {
                $recipients[] = $assigneeEmail;
            }
            $agent = (new TemplatedEmail())
                ->from('Contact <contact@relocation-in-paris.fr>')
                ->to(...$recipients)
                ->subject(\sprintf('📹 Visio annulée | %s le %s', $clientName, $visioAt->format('d.m à H\hi')))
                ->htmlTemplate('emails/contact_visio_agent.html.twig')
                ->context([
                    'mode' => 'cancelled',
                    'visioAt' => $visioAt,
                    'firstName' => $contact->getFirstName(),
                    'lastName' => $contact->getLastName(),
                    'emailContact' => $contact->getEmail(),
                    'phoneNumber' => $contact->getPhoneNumber(),
                    'lang' => $contact->getLang(),
                    'reference' => $contact->getReference(),
                    'meetLink' => null,
                    'detailUrl' => $this->urlGenerator->generate('admin_contact_show', [
                        '_locale' => 'fr',
                        'adminPrefix' => $this->adminPathPrefix,
                        'reference' => $contact->getReference(),
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                ]);
            $agent->addPart(new DataPart($ics, 'visio.ics', 'text/calendar'));

            $this->mailer->send($client);
            $this->mailer->send($agent);
        } catch (TransportExceptionInterface|HandlerFailedException|\Symfony\Component\Mime\Exception\ExceptionInterface $e) {
            // An email failure must never break the business action.
            $this->logger->error('Visio cancellation email failed: '.$e->getMessage(), [
                'reference' => $contact->getReference(),
            ]);
        }
    }

    private function buildIcs(
        Contact $contact,
        \DateTimeImmutable $visioAt,
        string $clientName,
        string $clientEmail,
        ?string $assigneeEmail,
        bool $fr,
        ?string $organizerEmail = null,
        bool $cancelled = false,
        ?string $meetLink = null,
        ?string $googleEventId = null,
    ): string {
        $utc = new \DateTimeZone('UTC');
        $start = $visioAt->setTimezone($utc);
        $end = $start->modify(\sprintf('+%d minutes', self::DURATION_MINUTES));
        $now = new \DateTimeImmutable('now', $utc);

        $description = \sprintf(
            '%s, Relocation in Paris. %s (%s)%s%s',
            ucfirst(self::videoCallPhrase($contact, $fr)),
            $clientName,
            $clientEmail,
            null !== $contact->getPhoneNumber() ? ' / '.$contact->getPhoneNumber() : '',
            null !== $meetLink ? ($fr ? "\nRejoindre : " : "\nJoin: ").$meetLink : '',
        );

        // The organizer matches the agenda hosting the Google event (the
        // closer when they organize the meeting, the agency inbox as the
        // fallback), so calendar clients merge instead of duplicating.
        $organizerEmail ??= EmailAddress::CONTACT->value;
        $assigneeName = trim(((string) $contact->getAssignedTo()?->getFirstName()).' '.((string) $contact->getAssignedTo()?->getLastName()));
        $organizerName = ('' !== $assigneeName && $organizerEmail === trim((string) $contact->getAssignedTo()?->getEmail()))
            ? $assigneeName
            : 'Relocation in Paris';

        // Aligned with the Google event when one exists, so calendar
        // clients merge the attachment with the API-created invite.
        $uid = null !== ($googleEventId ?? $contact->getVisioEventId())
            ? ($googleEventId ?? $contact->getVisioEventId()).'@google.com'
            : \sprintf('visio-contact-%d@relocation-in-paris.fr', (int) $contact->getId());

        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:-//Relocation in Paris//VideoCall//FR',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            $cancelled ? 'METHOD:CANCEL' : 'METHOD:REQUEST',
            'BEGIN:VEVENT',
            // Stable across reschedules: calendars update in place.
            'UID:'.$uid,
            \sprintf('SEQUENCE:%d', $now->getTimestamp()),
            \sprintf('DTSTAMP:%s', $now->format('Ymd\THis\Z')),
            \sprintf('DTSTART:%s', $start->format('Ymd\THis\Z')),
            \sprintf('DTEND:%s', $end->format('Ymd\THis\Z')),
            \sprintf('SUMMARY:%s', $this->escapeIcs(self::eventTitle($contact, $fr, $clientName))),
            \sprintf('DESCRIPTION:%s', $this->escapeIcs($description)),
            \sprintf('ORGANIZER;CN=%s:mailto:%s', $this->quoteIcsParam($organizerName), $organizerEmail),
            ...(null !== $meetLink ? ['URL:'.$meetLink] : []),
            ...($cancelled ? ['STATUS:CANCELLED'] : []),
            \sprintf(
                'ATTENDEE;CN=%s;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:%s',
                $this->quoteIcsParam($clientName),
                $clientEmail,
            ),
        ];
        if (null !== $assigneeEmail && '' !== $assigneeEmail) {
            $lines[] = \sprintf(
                'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:%s',
                $assigneeEmail,
            );
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->foldIcsLine(...), $lines))."\r\n";
    }

    private function escapeIcs(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    /**
     * iCalendar param value (CN=...): quoted, with the characters the RFC
     * forbids inside quoted strings stripped.
     */
    private function quoteIcsParam(string $text): string
    {
        return '"'.str_replace(['"', ';', ':', ',', "\r", "\n"], '', $text).'"';
    }

    /** RFC 5545 line folding: content lines capped at 75 octets. */
    private function foldIcsLine(string $line): string
    {
        $folded = '';
        while (\strlen($line) > 75) {
            $chunk = mb_strcut($line, 0, 75);
            $folded .= $chunk."\r\n ";
            $line = substr($line, \strlen($chunk));
        }

        return $folded.$line;
    }
}
