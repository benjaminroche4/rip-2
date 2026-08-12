<?php

declare(strict_types=1);

namespace App\Contact\Service;

use App\Contact\Domain\NextStep;
use App\Contact\Entity\Contact;
use App\Shared\Email\EmailAddress;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mirrors a planned recontact into the agent's Google agenda, the way the
 * visio flow does for meetings: a 15-minute event on the recall slot, kept
 * in sync across reschedules and dropped when the step no longer applies.
 * No Meet link: a recall is a phone/WhatsApp/email touchpoint.
 */
final readonly class RecallCalendarSync
{
    private const DURATION_MINUTES = 15;

    public function __construct(
        private GoogleCalendarClient $calendar,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        #[Autowire('%admin_path_prefix%')]
        private string $adminPathPrefix,
    ) {
    }

    /**
     * One idempotent entry point: reads the stored step and recall date,
     * creates or patches the event when a dated recontact stands, deletes
     * it otherwise. Call it after any mutation touching the step, the
     * date, or the assignee (a patch re-syncs the attendee list).
     */
    public function apply(Contact $contact): void
    {
        $recallAt = NextStep::Recontact === $contact->getNextStep() ? $contact->getRecallAt() : null;
        if (null === $recallAt) {
            $this->clear($contact);

            return;
        }

        $fullName = trim(((string) $contact->getFirstName()).' '.((string) $contact->getLastName()));
        $clientName = '' !== $fullName ? $fullName : (string) $contact->getEmail();
        $channel = $contact->getRecontactChannel();
        $channelLabel = null !== $channel ? $this->translator->trans($channel->labelKey(), locale: 'fr') : null;
        $assigneeEmail = $contact->getAssignedTo()?->getEmail();

        $descriptionLines = array_filter([
            \sprintf('%s / %s%s', $clientName, (string) $contact->getEmail(), null !== $contact->getPhoneNumber() ? ' / '.$contact->getPhoneNumber() : ''),
            null !== $channelLabel ? 'Canal : '.$channelLabel : null,
            'Fiche : '.$this->urlGenerator->generate('admin_contact_show', [
                '_locale' => 'fr',
                'adminPrefix' => $this->adminPathPrefix,
                'reference' => $contact->getReference(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $event = $this->calendar->upsertVisioEvent(
            $contact->getRecallEventId(),
            \sprintf('Rappel%s | %s', null !== $channelLabel ? ' '.$channelLabel : '', $clientName),
            implode("\n", $descriptionLines),
            $recallAt,
            $recallAt->modify(\sprintf('+%d minutes', self::DURATION_MINUTES)),
            // Internal event: the prospect is notified by our own opt-in
            // email, never by a Google invite.
            array_values(array_unique(array_filter([$assigneeEmail, EmailAddress::CONTACT->value]))),
            withMeet: false,
        );
        if (null !== $event) {
            $contact->setRecallEventId($event['eventId']);
            $this->em->flush();
            $this->logger->info('Recall event synced to the agenda', [
                'reference' => $contact->getReference(),
                'googleEvent' => $event['eventId'],
                'recallAt' => $recallAt->format(\DateTimeInterface::ATOM),
            ]);
        }
    }

    /**
     * Drops the agenda event (step changed, lead closed or deleted).
     * Missing events are fine: the goal is "not in the agenda".
     */
    public function clear(Contact $contact): void
    {
        $eventId = $contact->getRecallEventId();
        if (null === $eventId) {
            return;
        }

        $this->calendar->deleteEvent($eventId);
        $contact->setRecallEventId(null);
        $this->em->flush();
        $this->logger->info('Recall event removed from the agenda', [
            'reference' => $contact->getReference(),
            'googleEvent' => $eventId,
        ]);
    }
}
