<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Domain\ClientDecision;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use App\Visit\Service\DecisionReminderSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rappel staff "échéance de réflexion dépassée" (cron quotidien
 * app:visits:send-decision-reminders) : un seul email par visite le jour J
 * ou après, jamais deux (idempotence par decision_reminder_sent_at), et
 * seules les visites encore en "Réfléchit" avec une échéance atteinte sont
 * concernées.
 */
final class VisitDecisionReminderTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private const NOW = '2026-08-18 09:00:00';

    private EntityManagerInterface $em;
    private Dossier $dossier;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-reminder-test.local')->execute();

        $this->dossier = (new Dossier())
            ->setName('Famille Reminder')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($this->dossier);
        $this->em->flush();
    }

    public function testItSendsOnceOnOrAfterTheDeadlineAndNeverTwice(): void
    {
        $visit = $this->persistVisit(ClientDecision::Thinking, '2026-08-17');
        $sender = self::getContainer()->get(DecisionReminderSender::class);
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'));

        self::assertSame(1, $sender->send($now));
        self::assertEmailCount(1);
        $message = self::getMailerMessages()[0];
        self::assertStringContainsString('Échéance dépassée', (string) $message->getSubject());
        $body = (string) $message->getHtmlBody();
        self::assertStringContainsString('Famille Reminder', $body);
        self::assertStringContainsString((string) $visit->getReference(), $body);
        self::assertStringContainsString('/visites/'.$visit->getReference(), $body, 'The email links to the visit page.');

        $this->em->clear();
        self::assertNotNull($this->em->find(Visit::class, $visit->getId())->getDecisionReminderSentAt());

        // Deuxième run : plus rien à envoyer.
        self::assertSame(0, $sender->send($now));
    }

    public function testTheDeadlineDayItselfTriggersTheReminder(): void
    {
        $this->persistVisit(ClientDecision::Thinking, '2026-08-18');
        $sender = self::getContainer()->get(DecisionReminderSender::class);

        self::assertSame(1, $sender->send(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'))));
    }

    public function testItIgnoresFutureDeadlinesOtherDecisionsAndVisitsWithoutDeadline(): void
    {
        $this->persistVisit(ClientDecision::Thinking, '2026-08-19');       // future
        $this->persistVisit(ClientDecision::Positioning, '2026-08-10');    // not thinking
        $this->persistVisit(ClientDecision::Thinking, null);               // no deadline
        $sender = self::getContainer()->get(DecisionReminderSender::class);

        self::assertSame(0, $sender->send(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'))));
        self::assertEmailCount(0);
    }

    public function testItIgnoresCancelledAndNoShowVisits(): void
    {
        // Reliquats de décision sur des visites qui n'ont pas eu lieu : pas
        // de rappel, il n'y a aucun retour client à relancer.
        $this->persistVisit(ClientDecision::Thinking, '2026-08-10', status: VisitStatus::Cancelled);
        $this->persistVisit(ClientDecision::Thinking, '2026-08-10', status: VisitStatus::NoShow);
        $sender = self::getContainer()->get(DecisionReminderSender::class);

        self::assertSame(0, $sender->send(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'))));
        self::assertEmailCount(0);
    }

    public function testItIgnoresClosedDossiers(): void
    {
        $this->dossier->setClosedAt(new \DateTimeImmutable('2026-08-12'));
        $this->em->flush();
        $this->persistVisit(ClientDecision::Thinking, '2026-08-10');
        $sender = self::getContainer()->get(DecisionReminderSender::class);

        self::assertSame(0, $sender->send(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'))));
        self::assertEmailCount(0);
    }

    public function testARearmedVisitGetsASecondReminder(): void
    {
        // Après une prolongation, decision_reminder_sent_at est remis à null
        // (côté contrôleur) : le cron renvoie un rappel à la nouvelle
        // échéance.
        $visit = $this->persistVisit(ClientDecision::Thinking, '2026-08-14');
        $sender = self::getContainer()->get(DecisionReminderSender::class);
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'));

        self::assertSame(1, $sender->send($now));
        self::assertSame(0, $sender->send($now));

        $visit->setDecisionDeadline(new \DateTimeImmutable('2026-08-17'))
            ->setDecisionReminderSentAt(null);
        $this->em->flush();

        self::assertSame(1, $sender->send($now), 'A second reminder goes out after the deadline was extended.');
    }

    public function testTheRunIsBoundedByTheLimit(): void
    {
        $this->persistVisit(ClientDecision::Thinking, '2026-08-15');
        $this->persistVisit(ClientDecision::Thinking, '2026-08-16');
        $this->persistVisit(ClientDecision::Thinking, '2026-08-17');
        $sender = self::getContainer()->get(DecisionReminderSender::class);
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'));

        self::assertSame(2, $sender->send($now, limit: 2));
        // Le run suivant rattrape la visite restante.
        self::assertSame(1, $sender->send($now, limit: 2));
    }

    public function testTheConsoleCommandRunsGreen(): void
    {
        $this->persistVisit(ClientDecision::Thinking, '2026-08-01');

        $command = (new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel))
            ->find('app:visits:send-decision-reminders');
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--limit' => '10']));
        self::assertStringContainsString('decision reminder(s) sent', $tester->getDisplay());
    }

    private function persistVisit(ClientDecision $decision, ?string $deadline, VisitStatus $status = VisitStatus::Done): Visit
    {
        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($this->dossier)
            ->setScheduledAt(new \DateTimeImmutable('2026-08-10 10:30'))
            ->setStatus($status)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setClientDecision($decision)
            ->setClientDecisionAt(new \DateTimeImmutable('2026-08-10 12:00'))
            ->setDecisionDeadline(null !== $deadline ? new \DateTimeImmutable($deadline) : null)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }
}
