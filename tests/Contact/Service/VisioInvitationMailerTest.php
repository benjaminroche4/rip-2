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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mime\Email;
use Symfony\UX\CalendarLink\Registry\CalendarLinkProviderRegistry;

/**
 * The visio invitation path end to end: what lands in whose agenda, what is
 * emailed to the prospect (and from which address) and to the team, and
 * what the follow-up thread records. The Google API is a MockHttpClient
 * behind the real client, so the JWT/payload path runs for real and every
 * request (and the subject it impersonates) can be asserted.
 */
final class VisioInvitationMailerTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private const CENTRAL = 'agenda@relocation-in-paris.fr';

    private EntityManagerInterface $em;

    /** @var list<array{method: string, url: string, body: string, sub: ?string}> */
    private array $calls = [];

    /** Organizer email returned by the mocked event GET (null: none). */
    private ?string $getOrganizer = null;

    private string $keyFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visio-mailer-test.local')->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', 'vc-closer-%@relocation-in-paris.fr')->execute();

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
        $this->getOrganizer = null;
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
        $created = $this->callsBy('POST');
        self::assertCount(1, $created);
        self::assertStringContainsString((string) $contact->getEmail(), $created[0]['body']);
        self::assertStringContainsString('agent@visio-mailer-test.local', $created[0]['body']);
        self::assertStringContainsString('hangoutsMeet', $created[0]['body'], 'A Meet link is requested.');

        // Ids stored so a later reschedule patches instead of duplicating.
        self::assertSame('evt-1', $contact->getVisioEventId());
        self::assertSame('https://meet.google.com/abc-defg-hij', $contact->getVisioMeetLink());

        // One email to the prospect, one to the team, both carrying the ICS.
        self::assertEmailCount(2);
        $subjects = $this->subjects();
        self::assertNotEmpty(array_filter($subjects, static fn (string $s): bool => str_contains($s, 'appel vidéo')));
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            self::assertNotEmpty($message->getAttachments(), 'Every invitation carries the .ics.');
        }

        self::assertContains('visio_planned', $this->eventKinds($contact));
    }

    public function testTheEventLivesInTheClosersAgendaWithTheClientFacingTitle(): void
    {
        $closer = $this->persistCloser('Marc', 'Dupont');
        $contact = $this->persistContact(assignee: $closer);
        $mailer = $this->mailer();

        $mailer->send($contact);

        // The Meet event is organized by the closer: created under their
        // impersonated identity, in their own agenda.
        $created = $this->callsBy('POST');
        self::assertCount(1, $created);
        self::assertSame($closer->getEmail(), $created[0]['sub']);

        // "{Prénom client} • {Prénom closer} - ..." with a plain dash.
        $payload = json_decode($created[0]['body'], true);
        self::assertSame('jane • Marc - Votre nouvel appartement à Paris', $payload['summary']);

        // The confirmation email comes from the closer, name included.
        $client = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $client);
        self::assertSame($closer->getEmail(), $client->getFrom()[0]->getAddress());
        self::assertSame('Marc Dupont', $client->getFrom()[0]->getName());
        self::assertSame([], $client->getReplyTo());
    }

    public function testWithoutACloserEverythingFallsBackToTheCentralAddress(): void
    {
        $contact = $this->persistContact(assigned: false);
        $mailer = $this->mailer();

        $mailer->send($contact);

        // Event in the central agenda, closer part omitted from the title.
        $created = $this->callsBy('POST');
        self::assertCount(1, $created);
        self::assertSame(self::CENTRAL, $created[0]['sub']);
        $payload = json_decode($created[0]['body'], true);
        self::assertSame('jane - Votre nouvel appartement à Paris', $payload['summary']);

        // Email from the agency inbox, and the team copy only goes there.
        $client = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $client);
        self::assertSame('contact@relocation-in-paris.fr', $client->getFrom()[0]->getAddress());
    }

    public function testAnOffDomainCloserSendsFromTheCentralAddressWithReplyTo(): void
    {
        // The transactional provider only sends from the verified agency
        // domain: an off-domain closer cannot be the From, they get the
        // Reply-To instead, and the event stays in the central agenda.
        $contact = $this->persistContact();
        $mailer = $this->mailer();

        $mailer->send($contact);

        self::assertSame(self::CENTRAL, $this->callsBy('POST')[0]['sub']);
        $client = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $client);
        self::assertSame('contact@relocation-in-paris.fr', $client->getFrom()[0]->getAddress());
        self::assertSame('agent@visio-mailer-test.local', $client->getReplyTo()[0]->getAddress());
    }

    public function testAnEnglishClientGetsTheEnglishTitle(): void
    {
        $closer = $this->persistCloser('Marc', 'Dupont');
        $contact = $this->persistContact(assignee: $closer, lang: 'en');
        $mailer = $this->mailer();

        $mailer->send($contact);

        $payload = json_decode($this->callsBy('POST')[0]['body'], true);
        self::assertSame('jane • Marc - Your new Home in Paris', $payload['summary']);
        $client = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $client);
        self::assertStringContainsString('Your video call is confirmed', (string) $client->getSubject());
    }

    public function testTheDefaultDurationIsTwentyMinutes(): void
    {
        $contact = $this->persistContact();
        $mailer = $this->mailer();

        $mailer->send($contact);

        $payload = json_decode($this->callsBy('POST')[0]['body'], true);
        $paris = new \DateTimeZone('Europe/Paris');
        $start = $contact->getRecallAt()->setTimezone($paris);
        self::assertSame($start->format('Y-m-d\TH:i:s'), $payload['start']['dateTime']);
        self::assertSame($start->modify('+20 minutes')->format('Y-m-d\TH:i:s'), $payload['end']['dateTime']);

        $client = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $client);
        self::assertStringContainsString('20 min', (string) $client->getHtmlBody());
    }

    public function testClientFacingContentNeverSaysVisio(): void
    {
        $closer = $this->persistCloser('Marc', 'Dupont');
        $contact = $this->persistContact(assignee: $closer);
        $mailer = $this->mailer();

        $mailer->send($contact);

        // The Google event payload (title, description) is client-visible.
        foreach ($this->callsBy('POST') as $call) {
            self::assertDoesNotMatchRegularExpression('/visio/i', $call['body']);
        }

        // Subject, HTML body and the attached ICS of the prospect email too.
        $client = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $client);
        self::assertDoesNotMatchRegularExpression('/visio/i', (string) $client->getSubject());
        self::assertDoesNotMatchRegularExpression('/visio/i', (string) $client->getHtmlBody());
        $attachment = $client->getAttachments()[0];
        self::assertSame('invitation.ics', $attachment->getFilename());
        $ics = (string) $attachment->getBody();
        self::assertDoesNotMatchRegularExpression('/visio/i', $ics);
        self::assertStringContainsString('Appel vidéo avec Marc', str_replace("\r\n ", '', $ics));
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
        $patched = $this->callsBy('PATCH');
        self::assertCount(1, $patched);
        self::assertStringContainsString('evt-1', $patched[0]['url']);
        self::assertSame([], $this->callsBy('POST'), 'No duplicate event.');

        self::assertEmailCount(2);
        self::assertNotEmpty(
            array_filter($this->subjects(), static fn (string $s): bool => str_contains($s, 'déplacé')),
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

        $deleted = $this->callsBy('DELETE');
        self::assertCount(1, $deleted);
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

    public function testLifecycleActsOnTheOrganizersAgenda(): void
    {
        // Event organized by the closer: the cancellation must impersonate
        // them (an attendee-side delete would only decline the meeting).
        $closer = $this->persistCloser('Marc', 'Dupont');
        $contact = $this->persistContact(assignee: $closer);
        $mailer = $this->mailer();
        $mailer->send($contact);
        $this->clearMailerAndCalls();

        $this->getOrganizer = $closer->getEmail();
        $mailer->cancel($contact, notify: false);
        self::assertSame([$closer->getEmail()], array_column($this->callsBy('DELETE'), 'sub'));

        // Legacy event organized by the central address (created before the
        // closer-agenda era): the API-reported organizer wins over the
        // currently assigned closer.
        $legacy = $this->persistContact(assignee: $closer);
        $mailer->send($legacy);
        $this->clearMailerAndCalls();
        $this->getOrganizer = self::CENTRAL;
        $legacy->setRecallAt(new \DateTimeImmutable('+5 days 11:00'));
        $this->em->flush();
        $mailer->send($legacy, rescheduled: true);
        self::assertSame([self::CENTRAL], array_column($this->callsBy('PATCH'), 'sub'));
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

        self::assertCount(1, $this->callsBy('DELETE'));
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

        $patched = $this->callsBy('PATCH');
        self::assertCount(1, $patched);
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
                parse_str((string) $options['body'], $tokenBody);
                $claims = json_decode((string) base64_decode(strtr(explode('.', (string) $tokenBody['assertion'])[1], '-_', '+/'), true), true);
                $sub = (string) ($claims['sub'] ?? '');

                // One token per subject: each API call reveals whose agenda
                // it targets.
                return new MockResponse((string) json_encode(['access_token' => 'token-for-'.$sub]));
            }

            $auth = '';
            foreach ((array) ($options['normalized_headers']['authorization'] ?? []) as $header) {
                $auth = (string) $header;
            }
            $this->calls[] = [
                'method' => $method,
                'url' => $url,
                'body' => (string) ($options['body'] ?? ''),
                'sub' => 1 === preg_match('/token-for-(\S+)/', $auth, $m) ? $m[1] : null,
            ];

            $event = ['id' => 'evt-1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'];
            if ('GET' === $method && null !== $this->getOrganizer) {
                $event['organizer'] = ['email' => $this->getOrganizer];
            }

            return new MockResponse((string) json_encode($event));
        });

        $container = self::getContainer();

        return new VisioInvitationMailer(
            $container->get('mailer'),
            new GoogleCalendarClient(
                $http,
                $container->get('logger'),
                $configured ? $this->keyFile : '',
                $configured ? self::CENTRAL : '',
            ),
            $container->get(ContactEventRepository::class),
            $container->get(CalendarLinkProviderRegistry::class),
            $this->em,
            $container->get('router'),
            $container->get('logger'),
            'test_admin_prefix_1234567890abcdef',
        );
    }

    /** @return list<array{method: string, url: string, body: string, sub: ?string}> */
    private function callsTo(string $needle): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => str_contains($c['url'], $needle)));
    }

    /** @return list<array{method: string, url: string, body: string, sub: ?string}> */
    private function callsBy(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $method === $c['method']));
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

    private function persistContact(bool $assigned = true, ?User $assignee = null, string $lang = 'fr'): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang($lang)->setIp('127.0.0.1')
            ->setStatus(ContactStatus::InProgress)
            ->setNextStep(NextStep::Visio)
            ->setRecallAt(new \DateTimeImmutable('+3 days 14:00'))
            ->setCreatedAt(new \DateTimeImmutable('-1 day 10:00'));
        if ($assigned) {
            $contact->setAssignedTo($assignee ?? $this->persistUser('agent@visio-mailer-test.local'));
        }
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    /**
     * A closer on the agency domain: their agenda hosts the meeting and
     * their address is a verified sender.
     */
    private function persistCloser(string $firstName, string $lastName): User
    {
        return $this->persistUser(
            'vc-closer-'.bin2hex(random_bytes(4)).'@relocation-in-paris.fr',
            $firstName,
            $lastName,
        );
    }

    private function persistUser(string $email, string $firstName = 'Agent', string $lastName = 'Test'): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existing) {
            return $existing;
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)->setLastName($lastName)
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
