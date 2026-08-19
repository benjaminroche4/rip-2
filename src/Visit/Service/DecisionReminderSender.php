<?php

declare(strict_types=1);

namespace App\Visit\Service;

use App\Shared\Email\EmailAddress;
use App\Visit\Entity\Visit;
use App\Visit\Repository\VisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Staff reminders on the visit follow-up, cron-driven (o2switch has no
 * async workers). Two kinds share the mechanics: the overdue thinking
 * deadline (decision deadline reached while the client is still thinking)
 * and the missing report (visit done for more than 24 hours with an empty
 * agent report). Both go to the agency inbox and the dossier manager,
 * idempotent through their own *_sent_at column, marked only on success so
 * a transient SMTP outage retries on the next run; a failure never aborts
 * the run.
 */
final readonly class DecisionReminderSender
{
    public function __construct(
        private VisitRepository $visits,
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
    public function send(?\DateTimeImmutable $now = null, int $limit = 25): int
    {
        $today = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'));
        $sent = 0;

        foreach ($this->visits->findDecisionRemindersDue($today, $limit) as $visit) {
            if ($this->sendReminder($visit)) {
                ++$sent;
                $visit->setDecisionReminderSentAt($today);
                // Flush après CHAQUE envoi réussi : un crash (process tué
                // sur mutualisé) ou un chevauchement de runs ne rejoue pas
                // les rappels déjà partis.
                $this->em->flush();
            }
        }

        return $sent;
    }

    /**
     * Rappel "compte-rendu à remplir" : visites effectuées depuis plus de
     * 24 h sans retour de l'agent. Returns the number of emails sent.
     */
    public function sendReportReminders(?\DateTimeImmutable $now = null, int $limit = 25): int
    {
        $today = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'));
        $sent = 0;

        foreach ($this->visits->findReportRemindersDue($today, $limit) as $visit) {
            if ($this->sendReportReminder($visit)) {
                ++$sent;
                $visit->setReportReminderSentAt($today);
                // Flush après CHAQUE envoi réussi, comme le volet échéance.
                $this->em->flush();
            }
        }

        return $sent;
    }

    private function sendReportReminder(Visit $visit): bool
    {
        $dossier = $visit->getDossier();
        $scheduledAt = $visit->getScheduledAt();
        if (null === $scheduledAt) {
            return false;
        }

        try {
            // Mêmes destinataires que le rappel d'échéance : la boîte de
            // l'agence, plus le gestionnaire du dossier quand il existe.
            $recipients = [EmailAddress::CONTACT->value];
            $managerEmail = $dossier?->getManager()?->getEmail();
            if (null !== $managerEmail && '' !== $managerEmail && EmailAddress::CONTACT->value !== $managerEmail) {
                $recipients[] = $managerEmail;
            }

            $email = (new TemplatedEmail())
                ->from(new \Symfony\Component\Mime\Address(EmailAddress::CONTACT->value, 'Contact'))
                ->to(...$recipients)
                ->subject(\sprintf(
                    '📝 Compte-rendu à remplir | Visite du %s pour %s (%s)',
                    $scheduledAt->format('d.m'),
                    (string) $dossier?->getName(),
                    (string) $visit->getReference(),
                ))
                ->htmlTemplate('emails/visit_report_reminder.html.twig')
                ->context([
                    'dossierName' => (string) $dossier?->getName(),
                    'dossierReference' => (string) $dossier?->getReference(),
                    'visitAddress' => trim((string) $visit->getAddress()),
                    'scheduledAt' => $scheduledAt,
                    'reference' => (string) $visit->getReference(),
                    // Lien direct vers la card compte-rendu de la fiche.
                    'detailUrl' => $this->urlGenerator->generate('admin_visit_show', [
                        '_locale' => 'fr',
                        'adminPrefix' => $this->adminPathPrefix,
                        'reference' => (string) $visit->getReference(),
                    ], UrlGeneratorInterface::ABSOLUTE_URL).'#visit-report',
                ]);

            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Visit report reminder email failed: '.$e->getMessage(), [
                'visit' => (string) $visit->getReference(),
            ]);

            return false;
        }
    }

    private function sendReminder(Visit $visit): bool
    {
        $dossier = $visit->getDossier();
        $deadline = $visit->getDecisionDeadline();
        $scheduledAt = $visit->getScheduledAt();
        if (null === $deadline || null === $scheduledAt) {
            return false;
        }

        try {
            // Mêmes destinataires que les rappels de relance : la boîte de
            // l'agence, plus le gestionnaire du dossier quand il existe.
            $recipients = [EmailAddress::CONTACT->value];
            $managerEmail = $dossier?->getManager()?->getEmail();
            if (null !== $managerEmail && '' !== $managerEmail && EmailAddress::CONTACT->value !== $managerEmail) {
                $recipients[] = $managerEmail;
            }

            $email = (new TemplatedEmail())
                ->from(new \Symfony\Component\Mime\Address(EmailAddress::CONTACT->value, 'Contact'))
                ->to(...$recipients)
                ->subject(\sprintf(
                    '⏰ Échéance dépassée | %s attendu depuis le %s (%s)',
                    (string) $dossier?->getName(),
                    $deadline->format('d.m'),
                    (string) $visit->getReference(),
                ))
                ->htmlTemplate('emails/visit_decision_reminder.html.twig')
                ->context([
                    'dossierName' => (string) $dossier?->getName(),
                    'dossierReference' => (string) $dossier?->getReference(),
                    'visitAddress' => trim((string) $visit->getAddress()),
                    'scheduledAt' => $scheduledAt,
                    'deadline' => $deadline,
                    'reference' => (string) $visit->getReference(),
                    'detailUrl' => $this->urlGenerator->generate('admin_visit_show', [
                        '_locale' => 'fr',
                        'adminPrefix' => $this->adminPathPrefix,
                        'reference' => (string) $visit->getReference(),
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                ]);

            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Visit decision reminder email failed: '.$e->getMessage(), [
                'visit' => (string) $visit->getReference(),
            ]);

            return false;
        }
    }
}
