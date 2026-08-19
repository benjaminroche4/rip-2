<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use App\Visit\Service\DecisionReminderSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rappel staff "compte-rendu à remplir" (second volet du cron quotidien
 * app:visits:send-decision-reminders) : une visite effectuée depuis plus de
 * 24 h sans retour de l'agent déclenche un email, une seule fois
 * (idempotence par report_reminder_sent_at), réarmé quand la visite quitte
 * puis retrouve le statut Effectuée.
 */
final class VisitReportReminderTest extends WebTestCase
{
    private const NOW = '2026-08-19 09:00:00';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Dossier $dossier;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-report-reminder-test.local')->execute();

        $this->dossier = (new Dossier())
            ->setName('Famille Rappel')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($this->dossier);
        $this->em->flush();
    }

    public function testItSendsOnceAfter24HoursAndNeverTwice(): void
    {
        $visit = $this->persistVisit(scheduledAt: '2026-08-17 10:30');
        $sender = self::getContainer()->get(DecisionReminderSender::class);
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'));

        self::assertSame(1, $sender->sendReportReminders($now));
        self::assertEmailCount(1);
        $message = self::getMailerMessages()[0];
        self::assertStringContainsString('Compte-rendu à remplir', (string) $message->getSubject());
        $body = (string) $message->getHtmlBody();
        self::assertStringContainsString('Famille Rappel', $body);
        self::assertStringContainsString('/visites/'.$visit->getReference(), $body);
        self::assertStringContainsString('#visit-report', $body, 'The email links straight to the report card.');

        $this->em->clear();
        self::assertNotNull($this->em->find(Visit::class, $visit->getId())->getReportReminderSentAt());

        // Deuxième run : plus rien à envoyer.
        self::assertSame(0, $sender->sendReportReminders($now));
    }

    public function testAVisitDoneLessThan24HoursAgoWaits(): void
    {
        $this->persistVisit(scheduledAt: '2026-08-18 12:00');
        $sender = self::getContainer()->get(DecisionReminderSender::class);

        self::assertSame(0, $sender->sendReportReminders(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'))));
        self::assertEmailCount(0);
    }

    public function testAFilledReportSilencesTheReminder(): void
    {
        $this->persistVisit(scheduledAt: '2026-08-15 10:00', report: 'Très belle visite, client conquis.');
        // Un report fait d'espaces reste un report vide.
        $this->persistVisit(scheduledAt: '2026-08-15 11:00', report: '   ');
        $sender = self::getContainer()->get(DecisionReminderSender::class);

        self::assertSame(1, $sender->sendReportReminders(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'))));
    }

    public function testNonDoneVisitsAndClosedDossiersAreIgnored(): void
    {
        $this->persistVisit(scheduledAt: '2026-08-10 10:00', status: VisitStatus::Planned);
        $this->persistVisit(scheduledAt: '2026-08-10 11:00', status: VisitStatus::Cancelled);
        $this->persistVisit(scheduledAt: '2026-08-10 12:00', status: VisitStatus::NoShow);
        $this->dossier->setClosedAt(new \DateTimeImmutable('2026-08-16'));
        $this->persistVisit(scheduledAt: '2026-08-10 13:00');
        $this->em->flush();
        $sender = self::getContainer()->get(DecisionReminderSender::class);

        self::assertSame(0, $sender->sendReportReminders(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'))));
        self::assertEmailCount(0);
    }

    public function testTheRunIsBoundedByTheLimit(): void
    {
        $this->persistVisit(scheduledAt: '2026-08-14 10:00');
        $this->persistVisit(scheduledAt: '2026-08-15 10:00');
        $this->persistVisit(scheduledAt: '2026-08-16 10:00');
        $sender = self::getContainer()->get(DecisionReminderSender::class);
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'));

        self::assertSame(2, $sender->sendReportReminders($now, limit: 2));
        // Le run suivant rattrape la visite restante.
        self::assertSame(1, $sender->sendReportReminders($now, limit: 2));
    }

    public function testADowngradeRearmsTheReminderForTheNextDonePass(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(scheduledAt: '2026-08-15 10:00');
        $sender = self::getContainer()->get(DecisionReminderSender::class);
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'));

        self::assertSame(1, $sender->sendReportReminders($now));
        self::assertSame(0, $sender->sendReportReminders($now));

        // Rétrogradation (la visite n'a en fait pas eu lieu) puis re-passage
        // en Effectuée : la purge du contrôleur remet le marqueur à null.
        $this->postStatus($visit, 'planned');
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getReportReminderSentAt(), 'Leaving Done rearms the report reminder.');

        $this->postStatus($visit, 'done');
        self::assertSame(1, $sender->sendReportReminders($now), 'A visit done again gets a fresh reminder.');
    }

    public function testTheConsoleCommandCoversBothReminderKinds(): void
    {
        $this->persistVisit(scheduledAt: '2026-08-01 10:00');

        $command = (new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel))
            ->find('app:visits:send-decision-reminders');
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--limit' => '10']));
        self::assertStringContainsString('decision reminder(s) sent', $tester->getDisplay());
        self::assertStringContainsString('report reminder(s) sent', $tester->getDisplay());
    }

    /** Rejoue le geste réel : POST du statut avec le token de la fiche. */
    private function postStatus(Visit $visit, string $status): void
    {
        $url = '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getReference();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form[action$="/statut"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url.'/statut', [
            '_token' => $token,
            'status' => $status,
        ]);
        self::assertResponseStatusCodeSame(303);
    }

    private function persistVisit(string $scheduledAt, ?string $report = null, VisitStatus $status = VisitStatus::Done): Visit
    {
        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($this->dossier)
            ->setScheduledAt(new \DateTimeImmutable($scheduledAt))
            ->setStatus($status)
            ->setReport($report)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-report-reminder-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
