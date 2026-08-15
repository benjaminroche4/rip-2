<?php

declare(strict_types=1);

namespace App\Tests\Contact\Service;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\NextStep;
use App\Contact\Entity\Contact;
use App\Contact\Repository\ContactEventRepository;
use App\Contact\Service\GoogleCalendarClient;
use App\Contact\Service\VisioInvitationMailer;
use Symfony\UX\CalendarLink\Registry\CalendarLinkProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mime\Email;

/**
 * The visio invitation path end to end: what lands in the agenda, what is
 * emailed to the prospect and to the team, and what the follow-up thread
 * records. The Google API is a MockHttpClient behind the real client, so
 * the JWT/payload path runs for real and the requests can be asserted.
 */
final class VisioInvitationMailerTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;

    /** @var list<array{method: string, url: string, body: string}> */
    private array $calls = [];

    private string $keyFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visio-mailer-test.local')->execute();

        // Throwaway service-account key: the signing path runs for real,
        // nothing leaves the process.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \assert(false !== $key);
        openssl_pkey_export($key, $pem);
        $this->keyFile = (string) tempnam(sys_get_temp_dir(), 'gcal-visio-');
        file_put_contents($this->keyFile, (string) json_encode([
            'client_email' => 'visio@service-account.test',
            'private_key' => $pem,
        ]));
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    public function testPlanningAVisioInvitesBothSidesAndCreatesTheEvent(): void
    {
        $contact = $this->persistContact();
        $mailer = $this->mailer();

        $mailer->send($contact);

        // Event created (POST) with the client and the assignee invited.
        $created = $this->callsTo('calendar/v3');
        self::assertCount(1, $created);
        self::assertSame('POST', $created[0]['method']);
        self::assertStringContainsString((string) $contact->getEmail(), $created[0]['body']);
        self::assertStringContainsString('agent@visio-mailer-test.local', $created[0]['body']);
        self::assertStringContainsString('hangoutsMeet', $created[0]['body'], 'A Meet link is requested.');

        // Ids stored so a later reschedule patches instead of duplicating.
        self::assertSame('evt-1', $contact->getVisioEventId());
        self::assertSame('https://meet.google.com/abc-defg-hij', $contact->getVisioMeetLink());

        // One email to the prospect, one to the team, both carrying the ICS.
        self::assertEmailCount(2);
        $subjects = $this->subjects();
        self::assertNotEmpty(array_filter($subjects, static fn (string $s): bool => str_contains($s, 'visio')));
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            self::assertNotEmpty($message->getAttachments(), 'Every invitation carries the .ics.');
        }

        self::assertContains('visio_planned', $this->eventKinds($contact));
    }

    public function testReschedulingPatchesTheSameEventAndSaysSo(): void
    {
        $contact = $this->persistContact();
        $mailer = $this->mailer();
        $mailer->send($contact);
        $this->clearMailerAndCalls();

        $contact->setRecallAt(new \DateTimeImmutable('+4 days 09:30'));
        $this->em->flush();
        $mailer->send($contact, rescheduled: true);

        // Patched in place: the prospect keeps one entry in their calendar.
        $patched = $this->callsTo('calendar/v3');
        self::assertCount(1, $patched);
        self::assertSame('PATCH', $patched[0]['method']);
        self::assertStringContainsString('evt-1', $patched[0]['url']);

        self::assertEmailCount(2);
        self::assertNotEmpty(
            array_filter($this->subjects(), static fn (string $s): bool => str_contains($s, 'déplacée')),
            'The prospect is told the slot moved, not invited again.',
        );
        self::assertContains('visio_rescheduled', $this->eventKinds($contact));
    }

    public function testCancellingDropsTheEventNotifiesBothSidesAndTracesIt(): void
    {
        $contact = $this->persistContact();
        $mailer = $this->mailer();
        $mailer->send($contact);
        $this->clearMailerAndCalls();

        $mailer->cancel($contact);

        $deleted = $this->callsTo('calendar/v3');
        self::assertCount(1, $deleted);
        self::assertSame('DELETE', $deleted[0]['method']);
        self::assertNull($contact->getVisioEventId(), 'Nothing left to patch or cancel twice.');
        self::assertNull($contact->getVisioMeetLink());

        self::assertEmailCount(2);
        self::assertNotEmpty(array_filter($this->subjects(), static fn (string $s): bool => str_contains($s, 'annul')));
        // METHOD:CANCEL clears the meeting from non-Google calendars too.
        $cancellation = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $cancellation);
        $body = (string) $cancellation->getAttachments()[0]->getBody();
        self::assertStringContainsString('METHOD:CANCEL', $body);

        self::assertContains('visio_cancelled', $this->eventKinds($contact));
    }

    public function testASilentCancellationCleansTheAgendaWithoutEmailing(): void
    {
        $contact = $this->persistContact();
        $mailer = $this->mailer();
        $mailer->send($contact);
        $this->clearMailerAndCalls();

        // Administrative rollback (lead back to "new", deletion): the
        // prospect must not receive anything.
        $mailer->cancel($contact, notify: false);

        self::assertCount(1, $this->callsTo('calendar/v3'));
        self::assertEmailCount(0);
        self::assertContains('visio_cancelled', $this->eventKinds($contact), 'Still traced internally.');
    }

    public function testStatusChangesApplyTheDocumentedMatrix(): void
    {
        // Closed: cancelled and the prospect is told.
        $closed = $this->persistContact();
        $mailer = $this->mailer();
        $mailer->send($closed);
        $this->clearMailerAndCalls();
        $mailer->onStatusChange($closed, ContactStatus::Closed);
        self::assertEmailCount(2);
        self::assertNull($closed->getVisioEventId());

        // Back to "new": cleaned up silently, it is an internal rollback.
        $rolledBack = $this->persistContact();
        $mailer->send($rolledBack);
        $this->clearMailerAndCalls();
        $mailer->onStatusChange($rolledBack, ContactStatus::New);
        self::assertEmailCount(0);
        self::assertNull($rolledBack->getVisioEventId());

        // Converted: the meeting stands, nothing is sent or deleted.
        $converted = $this->persistContact();
        $mailer->send($converted);
        $this->clearMailerAndCalls();
        $mailer->onStatusChange($converted, ContactStatus::Converted);
        self::assertEmailCount(0);
        self::assertSame('evt-1', $converted->getVisioEventId(), 'The kept meeting stays reachable.');
        self::assertContains('visio_kept', $this->eventKinds($converted));

        // Still in progress: no side effect at all.
        $ongoing = $this->persistContact();
        $mailer->send($ongoing);
        $this->clearMailerAndCalls();
        $mailer->onStatusChange($ongoing, ContactStatus::InProgress);
        self::assertEmailCount(0);
        self::assertSame([], $this->callsTo('calendar/v3'));
    }

    public function testReassignmentSwapsTheAttendeeWithoutEmailingAgain(): void
    {
        $contact = $this->persistContact();
        $mailer = $this->mailer();
        $mailer->send($contact);
        $this->clearMailerAndCalls();

        $newAgent = $this->persistUser('agent2@visio-mailer-test.local');
        $contact->setAssignedTo($newAgent);
        $this->em->flush();
        $mailer->refreshAttendees($contact);

        $patched = $this->callsTo('calendar/v3');
        self::assertCount(1, $patched);
        self::assertSame('PATCH', $patched[0]['method']);
        self::assertStringContainsString('agent2@visio-mailer-test.local', $patched[0]['body']);
        // The meeting itself did not change: nobody is emailed again.
        self::assertEmailCount(0);
    }

    public function testAnUnconfiguredCalendarStillEmailsTheInvitation(): void
    {
        $contact = $this->persistContact();
        // No key file: Google is off (dev, or an outage on the API side).
        $mailer = $this->mailer(configured: false);

        $mailer->send($contact);

        self::assertSame([], $this->callsTo('calendar/v3'));
        self::assertNull($contact->getVisioEventId());
        // The ICS fallback is the whole point: the meeting still reaches
        // both calendars through the attachment.
        self::assertEmailCount(2);
        $invitation = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $invitation);
        self::assertNotEmpty($invitation->getAttachments());
    }

    private function mailer(bool $configured = true): VisioInvitationMailer
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'test-token']));
            }
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return new MockResponse((string) json_encode([
                'id' => 'evt-1',
                'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
            ]));
        });

        $container = self::getContainer();

        return new VisioInvitationMailer(
            $container->get('mailer'),
            new GoogleCalendarClient(
                $http,
                $container->get('logger'),
                $configured ? $this->keyFile : '',
                $configured ? 'agenda@relocation-in-paris.fr' : '',
            ),
            $container->get(ContactEventRepository::class),
            $container->get(CalendarLinkProviderRegistry::class),
            $this->em,
            $container->get('router'),
            $container->get('logger'),
            'test_admin_prefix_1234567890abcdef',
        );
    }

    /** @return list<array{method: string, url: string, body: string}> */
    private function callsTo(string $needle): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => str_contains($c['url'], $needle)));
    }

    /** @return list<string> */
    private function subjects(): array
    {
        return array_map(
            static fn ($m): string => $m instanceof Email ? (string) $m->getSubject() : '',
            self::getMailerMessages(),
        );
    }

    /** @return list<string> */
    private function eventKinds(Contact $contact): array
    {
        return array_values(array_filter(array_map(
            static fn ($e): ?string => $e->kind,
            self::getContainer()->get(ContactEventRepository::class)->listForContact((int) $contact->getId()),
        )));
    }

    private function clearMailerAndCalls(): void
    {
        $this->calls = [];
        // The mailer collector is reset by rebooting the profiler-less
        // kernel client; here the simplest reliable reset is a new event
        // logger, obtained by clearing the collected messages.
        self::getContainer()->get('mailer.message_logger_listener')->reset();
    }

    private function persistContact(): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setStatus(ContactStatus::InProgress)
            ->setNextStep(NextStep::Visio)
            ->setRecallAt(new \DateTimeImmutable('+3 days 14:00'))
            ->setCreatedAt(new \DateTimeImmutable('-1 day 10:00'));
        $contact->setAssignedTo($this->persistUser('agent@visio-mailer-test.local'));
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function persistUser(string $email): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existing) {
            return $existing;
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Agent')->setLastName('Test')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
