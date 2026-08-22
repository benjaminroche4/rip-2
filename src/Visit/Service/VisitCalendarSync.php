<?php

declare(strict_types=1);

namespace App\Visit\Service;

use App\Contact\Service\CloserSender;
use App\Contact\Service\GoogleCalendarClient;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mirrors every planned visit into Google Calendar, twice: a "central"
 * event in the dossier manager's (the closer's) own Workspace agenda,
 * falling back to the default central agenda when the dossier has no
 * manager with an agency-domain address, and a copy in the assignee's
 * personal agenda when the visit has one. When the closer IS the
 * assignee, the central event in their agenda is enough: no personal
 * twin is created (it would duplicate the event in the same agenda).
 * Titles and description are rendered in French on purpose: the internal
 * agendas are French-only, like the recall mirror (RecallCalendarSync).
 *
 * The dossier contacts are invited as guests on the central event
 * (sendUpdates=all: Google mails them the invitation, every update and the
 * cancellation), which then carries a client-safe description; the full
 * internal one stays on the assignee's personal event.
 *
 * Best-effort throughout: a Calendar hiccup never blocks a booking. The
 * caller flushes the entity after sync() so the stored event ids persist.
 */
final readonly class VisitCalendarSync
{
    private const TIMEZONE = 'Europe/Paris';

    public function __construct(
        private GoogleCalendarClient $calendar,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        #[Autowire('%admin_path_prefix%')]
        private string $adminPathPrefix,
    ) {
    }

    /**
     * One idempotent entry point, called after any mutation touching the
     * slot, the address, the type, the assignee or the status: creates or
     * patches both events, moves the personal event to the new assignee's
     * agenda on reassignment, and deletes everything once cancelled.
     * Never throws; the caller flushes the updated event ids.
     */
    public function sync(Visit $visit): void
    {
        if (!$this->calendar->isConfigured()) {
            return;
        }

        try {
            $this->doSync($visit);
        } catch (\Throwable $e) {
            $this->logger->warning('Visit calendar sync failed: '.$e->getMessage(), ['visit' => $visit->getReference()]);
        }
    }

    /**
     * Drops both agenda events (visit about to be deleted). Never throws.
     */
    public function forget(Visit $visit): void
    {
        if (!$this->calendar->isConfigured()) {
            return;
        }

        try {
            $this->deleteCentral($visit);
            $this->deleteAssignee($visit);
        } catch (\Throwable $e) {
            $this->logger->warning('Visit calendar cleanup failed: '.$e->getMessage(), ['visit' => $visit->getReference()]);
        }
    }

    /**
     * First busy interval of the assignee's own Google agenda overlapping
     * the slot, for the blocking guard: any event counts (visit, lead
     * visio, personal meeting...). Null when the agenda is free, or when
     * the API is unconfigured/unavailable (best-effort: the network never
     * produces a false block; the caller still applies the DB guard).
     *
     * When editing a visit, its own mirrored event shows up busy: a busy
     * interval matching exactly the currently stored slot is tolerated.
     * Known limit: an unrelated event with those exact bounds is tolerated
     * too; acceptable, the stored visit already occupies that slot anyway.
     *
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}|null
     */
    public function findAssigneeBusyInterval(
        string $assigneeEmail,
        \DateTimeImmutable $start,
        int $durationMinutes,
        ?\DateTimeImmutable $currentStart = null,
        ?int $currentDurationMinutes = null,
    ): ?array {
        if (!$this->calendar->isConfigured()) {
            return null;
        }

        $paris = new \DateTimeZone(self::TIMEZONE);
        // Stored slots are Paris wall time: rebuild timezone-aware instants.
        $slotStart = new \DateTimeImmutable($start->format('Y-m-d H:i:s'), $paris);
        $slotEnd = $slotStart->modify(\sprintf('+%d minutes', max(1, $durationMinutes)));

        $busy = $this->calendar->freeBusy($slotStart, $slotEnd, $assigneeEmail);
        if (null === $busy) {
            // API down, delegation missing the freebusy scope, unreadable
            // agenda: unknown availability must never block the booking.
            return null;
        }

        $ignore = null;
        if (null !== $currentStart) {
            $ignoreStart = new \DateTimeImmutable($currentStart->format('Y-m-d H:i:s'), $paris);
            $ignore = [$ignoreStart, $ignoreStart->modify(\sprintf('+%d minutes', max(1, $currentDurationMinutes ?? $durationMinutes)))];
        }

        foreach ($busy as $interval) {
            try {
                $busyStart = (new \DateTimeImmutable($interval['start']))->setTimezone($paris);
                $busyEnd = (new \DateTimeImmutable($interval['end']))->setTimezone($paris);
            } catch (\Throwable) {
                continue;
            }
            // The visit being edited mirrors itself into the agenda.
            if (null !== $ignore
                && $busyStart->getTimestamp() === $ignore[0]->getTimestamp()
                && $busyEnd->getTimestamp() === $ignore[1]->getTimestamp()) {
                continue;
            }
            // Real overlap: startA < endB and startB < endA.
            if ($busyStart < $slotEnd && $slotStart < $busyEnd) {
                return ['start' => $busyStart, 'end' => $busyEnd];
            }
        }

        return null;
    }

    private function doSync(Visit $visit): void
    {
        $scheduledAt = $visit->getScheduledAt();
        // Cancelled (or slotless) visits leave both agendas entirely.
        if (null === $scheduledAt || VisitStatus::Cancelled === $visit->getStatus()) {
            $this->deleteCentral($visit);
            $this->deleteAssignee($visit);

            return;
        }

        $payload = $this->basePayload($visit, $scheduledAt);
        $assigneeName = $this->assigneeName($visit);

        // (a) Central event: every planned visit, assignee in the title, in
        // the agenda resolved by centralOwner() (the dossier manager's, or
        // the default central agenda). The dossier contacts are invited on
        // this event (Google mails the invitation and every update itself:
        // sendUpdates=all), so its description switches to a client-safe one
        // whenever guests are present; the internal details stay on the
        // personal event (or on this one when no client is invited).
        $attendees = $this->clientAttendees($visit);
        $centralTitle = \sprintf(
            '%s · %s (%s) · %s',
            $this->typeLabel($visit),
            (string) $visit->getDossier()?->getName(),
            (string) $visit->getReference(),
            $assigneeName ?? $this->translator->trans('admin.visits.calendar.autonomous', locale: 'fr'),
        );
        $centralPayload = [...$payload, 'summary' => $centralTitle, 'attendees' => $attendees];
        if ([] !== $attendees) {
            $centralPayload['description'] = $this->clientSafeDescription($visit);
        }
        $centralOwner = $this->centralOwner($visit);
        $central = $this->calendar->upsertEvent(
            $centralPayload,
            $visit->getCalendarCentralEventId(),
            $centralOwner,
            sendUpdates: 'all',
        );
        if (null !== $central && isset($central['id'])) {
            $visit->setCalendarCentralEventId((string) $central['id']);
            $visit->setCalendarCentralOwner($centralOwner);
        }

        // (b) Personal agenda of the assignee, when there is one.
        $assigneeEmail = trim((string) $visit->getAssignee()?->getEmail());
        $assigneeEmail = '' !== $assigneeEmail ? $assigneeEmail : null;

        // The closer does the visit themselves: the central event already
        // lives in their agenda, a personal twin would duplicate it there.
        // Any stale personal copy (created before the dossier had this
        // manager, or before a reassignment to them) is cleaned up.
        if (null !== $assigneeEmail && null !== $centralOwner && 0 === strcasecmp($centralOwner, $assigneeEmail)) {
            $this->deleteAssignee($visit);

            return;
        }

        // Reassignment (or unassignment): the old assignee's agenda must
        // not keep an event for a visit that is no longer theirs. When the
        // deletion fails (network), the old id/email pair is kept so the
        // next mutation retries; creating the new personal event now would
        // overwrite that pair and orphan the old event, so it waits too.
        $previousEmail = $visit->getCalendarAssigneeEmail();
        if (null !== $previousEmail && $previousEmail !== $assigneeEmail && !$this->deleteAssignee($visit)) {
            return;
        }

        if (null === $assigneeEmail) {
            if (null !== $visit->getAssignee()) {
                $this->logger->warning('Visit assignee has no email, personal agenda skipped', ['visit' => $visit->getReference()]);
            }

            return;
        }

        $personalTitle = \sprintf(
            '%s · %s (%s)',
            $this->typeLabel($visit),
            (string) $visit->getDossier()?->getName(),
            (string) $visit->getReference(),
        );
        $personal = $this->calendar->upsertEvent(
            [...$payload, 'summary' => $personalTitle],
            $visit->getCalendarAssigneeEventId(),
            $assigneeEmail,
        );
        if (null !== $personal && isset($personal['id'])) {
            $visit->setCalendarAssigneeEventId((string) $personal['id']);
            $visit->setCalendarAssigneeEmail($assigneeEmail);
        } else {
            // Impersonation refused or API down: logged by the client, the
            // central event still stands.
            $this->logger->warning('Visit could not be mirrored to the assignee agenda', ['visit' => $visit->getReference(), 'assignee' => $assigneeEmail]);
        }
    }

    /**
     * Whose agenda hosts (or should host) the central event. An existing
     * event keeps living where it was created: the owner stored at creation
     * wins (null = the default central agenda, which is also where every
     * legacy event lives), so a manager change never moves the event and
     * every PATCH/DELETE targets the right agenda without a network GET
     * (same outcome as the visio organizer resolution, but deterministic
     * and free even when the API is down). A new event goes to the dossier
     * manager's Workspace agenda, default central agenda as the fallback.
     */
    private function centralOwner(Visit $visit): ?string
    {
        if (null !== $visit->getCalendarCentralEventId()) {
            return $visit->getCalendarCentralOwner();
        }

        return CloserSender::workspaceEmail($visit->getDossier()?->getManager());
    }

    /**
     * Shared event body: slot, address and the full French description.
     * No Meet link, and no attendees here: the dossier contacts are only
     * invited on the central event (doSync), never on the personal one.
     *
     * @return array<string, mixed>
     */
    private function basePayload(Visit $visit, \DateTimeImmutable $scheduledAt): array
    {
        // scheduledAt is stored as Paris wall time: format it as-is with an
        // explicit timeZone, never shift it through setTimezone().
        $end = $scheduledAt->modify(\sprintf('+%d minutes', max(1, $visit->getDurationMinutes())));

        return [
            'location' => (string) $visit->getAddress(),
            'start' => ['dateTime' => $scheduledAt->format('Y-m-d\TH:i:s'), 'timeZone' => self::TIMEZONE],
            'end' => ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => self::TIMEZONE],
            'description' => $this->description($visit),
        ];
    }

    /**
     * Guests to invite on the central event: every dossier contact with a
     * valid email, deduplicated. Only when the client actually attends the
     * visit; an agent-only visit (inspection, autonomous viewing) must not
     * invite anyone. Always sent in the payload (even empty) so a PATCH
     * keeps the guest list in sync with the dossier and with a toggled
     * "client present" flag.
     *
     * @return list<array{email: string}>
     */
    private function clientAttendees(Visit $visit): array
    {
        if (!$visit->isClientPresent()) {
            return [];
        }

        $emails = [];
        foreach ($visit->getDossier()?->getPersons() ?? [] as $person) {
            $address = trim((string) $person->getEmail());
            if (false === filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $emails[mb_strtolower($address)] ??= $address;
        }

        return array_map(static fn (string $email): array => ['email' => $email], array_values($emails));
    }

    /**
     * Description of the central event when clients are invited on it: a
     * Google event description is visible to every guest, so nothing
     * internal may leak into it. In particular no back-office link (the
     * admin path prefix stays secret), no internal note, and no dossier
     * reference; the full internal description lives on the personal event.
     */
    private function clientSafeDescription(Visit $visit): string
    {
        $listingUrl = trim((string) $visit->getListingUrl());

        return implode("\n", array_filter([
            'Type : '.$this->typeLabel($visit),
            $this->agentLine($visit),
            '' !== $listingUrl ? 'Annonce : '.$listingUrl : null,
        ]));
    }

    private function agentLine(Visit $visit): ?string
    {
        $agent = $visit->getAgent();
        if (null === $agent) {
            return null;
        }

        $agentParts = [trim($agent->getFirstName().' '.$agent->getLastName())];
        $agency = trim((string) $agent->getAgency()?->getName());
        if ('' !== $agency) {
            $agentParts[] = '('.$agency.')';
        }
        $phone = trim((string) $agent->getPhone());
        if ('' !== $phone) {
            $agentParts[] = '· '.$phone;
        }

        return 'Agent immobilier : '.implode(' ', $agentParts);
    }

    private function description(Visit $visit): string
    {
        $agentLine = $this->agentLine($visit);

        $note = trim((string) $visit->getNote());
        $listingUrl = trim((string) $visit->getListingUrl());

        $lines = array_filter([
            \sprintf('Dossier : %s (%s)', (string) $visit->getDossier()?->getName(), (string) $visit->getDossier()?->getReference()),
            'Type : '.$this->typeLabel($visit),
            $agentLine,
            'Client présent : '.($visit->isClientPresent() ? 'Oui' : 'Non'),
            '' !== $listingUrl ? 'Annonce : '.$listingUrl : null,
            '' !== $note ? 'Note : '.$note : null,
            'Fiche visite : '.$this->urlGenerator->generate('admin_visit_show', [
                '_locale' => 'fr',
                'adminPrefix' => $this->adminPathPrefix,
                'reference' => (string) $visit->getReference(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return implode("\n", $lines);
    }

    private function typeLabel(Visit $visit): string
    {
        return $this->translator->trans($visit->getType()->labelKey(), locale: 'fr');
    }

    private function assigneeName(Visit $visit): ?string
    {
        $assignee = $visit->getAssignee();
        if (null === $assignee) {
            return null;
        }

        $name = trim(($assignee->getFirstName() ?? '').' '.($assignee->getLastName() ?? ''));

        return '' !== $name ? $name : (string) $assignee->getEmail();
    }

    /**
     * The stored id is only cleared when Google confirmed the deletion (or
     * the event no longer exists): on a network failure the id survives so
     * the next mutation retries the cleanup instead of orphaning the event.
     */
    private function deleteCentral(Visit $visit): bool
    {
        $eventId = $visit->getCalendarCentralEventId();
        if (null === $eventId) {
            return true;
        }
        // Deleted under its owner's identity (the agenda the event was
        // created in), and sendUpdates=all so invited dossier contacts get
        // the cancellation.
        if (!$this->calendar->deleteEvent($eventId, $visit->getCalendarCentralOwner(), sendUpdates: 'all')) {
            $this->logger->warning('Visit central agenda event could not be deleted, id kept for retry', ['visit' => $visit->getReference()]);

            return false;
        }
        $visit->setCalendarCentralEventId(null);
        $visit->setCalendarCentralOwner(null);

        return true;
    }

    /** Same retry contract as deleteCentral, on the personal agenda event. */
    private function deleteAssignee(Visit $visit): bool
    {
        $eventId = $visit->getCalendarAssigneeEventId();
        $email = $visit->getCalendarAssigneeEmail();
        if (null !== $eventId && null !== $email && !$this->calendar->deleteEvent($eventId, $email)) {
            $this->logger->warning('Visit assignee agenda event could not be deleted, id kept for retry', ['visit' => $visit->getReference(), 'assignee' => $email]);

            return false;
        }
        $visit->setCalendarAssigneeEventId(null);
        $visit->setCalendarAssigneeEmail(null);

        return true;
    }
}
