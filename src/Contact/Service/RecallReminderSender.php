<?php

declare(strict_types=1);

namespace App\Contact\Service;

use App\Contact\Entity\Contact;
use App\Contact\Repository\ContactRepository;
use App\Shared\Email\EmailAddress;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the planned-recall reminder emails, cron-driven (o2switch has no
 * async workers): the assignee and the agency inbox get a reminder the day
 * before, 1 hour before and 5 minutes before the recall.
 *
 * Only the most imminent applicable window is sent on each run — a recall
 * planned 30 minutes ahead gets the "1 hour" reminder once, not a backlog
 * of three emails — and wider windows are marked as sent alongside it.
 */
final readonly class RecallReminderSender
{
    /** label => [offset, sent-marker getter/setter suffix] */
    private const WINDOWS = [
        'soon' => '+5 minutes',
        'hour' => '+1 hour',
        'day' => '+1 day',
    ];

    public function __construct(
        private ContactRepository $contacts,
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        #[Autowire('%admin_path_prefix%')]
        private string $adminPathPrefix,
    ) {
    }

    /**
     * Returns the number of reminder emails actually sent.
     */
    public function send(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $sent = 0;

        foreach ($this->contacts->findWithUpcomingRecall($now) as $contact) {
            $window = $this->dueWindow($contact, $now);
            if (null === $window) {
                continue;
            }

            if ($this->sendReminder($contact, $window)) {
                ++$sent;
                // Marked only on success: a transient SMTP outage retries
                // on the next cron run instead of losing the reminder.
                $this->markSentUpTo($contact, $window, $now);
            }
        }

        $this->em->flush();

        return $sent;
    }

    /**
     * Narrowest window the recall has entered whose reminder is still
     * unsent; null when nothing is due.
     */
    private function dueWindow(Contact $contact, \DateTimeImmutable $now): ?string
    {
        $recallAt = $contact->getRecallAt();
        if (null === $recallAt) {
            return null;
        }

        foreach (self::WINDOWS as $window => $offset) {
            if ($recallAt <= $now->modify($offset) && null === $this->sentAt($contact, $window)) {
                return $window;
            }
        }

        return null;
    }

    private function sendReminder(Contact $contact, string $window): bool
    {
        try {
            $recipients = [EmailAddress::CONTACT->value];
            $assigneeEmail = $contact->getAssignedTo()?->getEmail();
            if (null !== $assigneeEmail && '' !== $assigneeEmail && EmailAddress::CONTACT->value !== $assigneeEmail) {
                $recipients[] = $assigneeEmail;
            }

            $fullName = trim(((string) $contact->getFirstName()).' '.((string) $contact->getLastName()));
            $recallAt = $contact->getRecallAt();

            $email = (new TemplatedEmail())
                ->from('Contact <contact@relocation-in-paris.fr>')
                ->to(...$recipients)
                ->subject(\sprintf(
                    '⏰ Rappel %s | %s %s le %s',
                    match ($window) {
                        'soon' => 'dans 5 minutes',
                        'hour' => 'dans 1 heure',
                        default => 'demain',
                    },
                    '' !== $fullName ? $fullName : (string) $contact->getEmail(),
                    match ($contact->getNextStep()) {
                        \App\Contact\Domain\NextStep::Visio => 'en visio',
                        \App\Contact\Domain\NextStep::QuoteSent => 'à relancer (devis)',
                        default => 'à recontacter',
                    },
                    $recallAt?->format('d.m à H\hi') ?? '',
                ))
                ->htmlTemplate('emails/contact_recall_reminder.html.twig')
                ->context([
                    'window' => $window,
                    'nextStep' => $contact->getNextStep()?->value,
                    'meetLink' => $contact->getVisioMeetLink(),
                    'recallAt' => $recallAt,
                    'firstName' => $contact->getFirstName(),
                    'lastName' => $contact->getLastName(),
                    'emailContact' => $contact->getEmail(),
                    'phoneNumber' => $contact->getPhoneNumber(),
                    'helpType' => $contact->getHelpType(),
                    'lang' => $contact->getLang(),
                    'recontactChannel' => $contact->getRecontactChannel()?->value,
                    'leadNote' => $contact->getLeadNote(),
                    'reference' => $contact->getReference(),
                    'detailUrl' => $this->urlGenerator->generate('admin_contact_show', [
                        '_locale' => 'fr',
                        'adminPrefix' => $this->adminPathPrefix,
                        'reference' => $contact->getReference(),
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                ]);

            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface|HandlerFailedException|\Symfony\Component\Mime\Exception\ExceptionInterface $e) {
            $this->logger->error('Recall reminder email failed: '.$e->getMessage(), [
                'reference' => $contact->getReference(),
                'window' => $window,
            ]);

            return false;
        }
    }

    /**
     * Marks the sent window AND every wider one, so a late cron never
     * follows up with the outdated wider reminders.
     */
    private function markSentUpTo(Contact $contact, string $window, \DateTimeImmutable $now): void
    {
        $reached = false;
        foreach (array_keys(self::WINDOWS) as $candidate) {
            if ($candidate === $window) {
                $reached = true;
            }
            if ($reached) {
                $this->markSent($contact, $candidate, $now);
            }
        }
    }

    private function sentAt(Contact $contact, string $window): ?\DateTimeImmutable
    {
        return match ($window) {
            'soon' => $contact->getRecallReminderSoonSentAt(),
            'hour' => $contact->getRecallReminderHourSentAt(),
            default => $contact->getRecallReminderDaySentAt(),
        };
    }

    private function markSent(Contact $contact, string $window, \DateTimeImmutable $now): void
    {
        match ($window) {
            'soon' => $contact->setRecallReminderSoonSentAt($now),
            'hour' => $contact->setRecallReminderHourSentAt($now),
            default => $contact->setRecallReminderDaySentAt($now),
        };
    }
}
