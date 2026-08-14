<?php

declare(strict_types=1);

namespace App\Tests\Contact\Service;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\NextStep;
use App\Contact\Domain\RecontactChannel;
use App\Contact\Entity\Contact;
use App\Contact\Service\GoogleCalendarClient;
use App\Contact\Service\RecallCalendarSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The agenda mirror of a planned recall: created when a dated recontact
 * stands, moved with the slot, dropped as soon as it no longer applies.
 * Unlike the visio it never invites the prospect (no Meet, no Google
 * invite): they are warned by our own opt-in email instead.
 */
final class RecallCalendarSyncTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<array{method: string, url: string, body: string}> */
    private array $calls = [];

    private string $keyFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@recall-sync-test.local')->execute();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \assert(false !== $key);
        openssl_pkey_export($key, $pem);
        $this->keyFile = (string) tempnam(sys_get_temp_dir(), 'gcal-recall-');
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

    public function testADatedRecontactLandsInTheAgentAgendaWithoutTheProspect(): void
    {
        $contact = $this->persistContact();
        $sync = $this->sync();

        $sync->apply($contact);

        self::assertCount(1, $this->calls);
        self::assertSame('POST', $this->calls[0]['method']);
        $body = $this->calls[0]['body'];
        self::assertStringContainsString('Rappel', $body);
        self::assertStringContainsString('agent@recall-sync-test.local', $body);
        // The prospect is never a Google attendee on an internal recall
        // (they only appear in the description, for the agent to read).
        $payload = json_decode($body, true);
        self::assertSame(
            ['agent@recall-sync-test.local', 'contact@relocation-in-paris.fr'],
            array_column($payload['attendees'], 'email'),
        );
        self::assertStringContainsString((string) $contact->getEmail(), (string) $payload['description']);
        self::assertStringNotContainsString('hangoutsMeet', $body, 'A recall is a phone touchpoint, not a meeting.');
        // The channel rides along so the agent knows how to reach them.
        self::assertStringContainsString('WhatsApp', $body);

        self::assertSame('evt-1', $contact->getRecallEventId(), 'Stored so the next apply() patches.');
    }

    public function testMovingTheSlotPatchesTheSameEvent(): void
    {
        $contact = $this->persistContact();
        $sync = $this->sync();
        $sync->apply($contact);
        $this->calls = [];

        $contact->setRecallAt(new \DateTimeImmutable('+5 days 11:00'));
        $this->em->flush();
        $sync->apply($contact);

        self::assertCount(1, $this->calls);
        self::assertSame('PATCH', $this->calls[0]['method']);
        self::assertStringContainsString('evt-1', $this->calls[0]['url']);
        self::assertSame('evt-1', $contact->getRecallEventId());
    }

    public function testLeavingTheRecontactStepDropsTheEvent(): void
    {
        $contact = $this->persistContact();
        $sync = $this->sync();
        $sync->apply($contact);
        $this->calls = [];

        // The step became a visio (or a dateless one): the recall mirror
        // has nothing left to mirror.
        $contact->setNextStep(NextStep::Visio);
        $this->em->flush();
        $sync->apply($contact);

        self::assertCount(1, $this->calls);
        self::assertSame('DELETE', $this->calls[0]['method']);
        self::assertNull($contact->getRecallEventId(), 'No stale id left behind.');
    }

    public function testApplyIsANoOpWithoutADateAndClearIsIdempotent(): void
    {
        $contact = $this->persistContact();
        $contact->setRecallAt(null);
        $this->em->flush();
        $sync = $this->sync();

        // Nothing planned, nothing stored: no call at all.
        $sync->apply($contact);
        $sync->clear($contact);
        self::assertSame([], $this->calls);
        self::assertNull($contact->getRecallEventId());
    }

    public function testClearRemovesAPlannedRecallFromTheAgenda(): void
    {
        $contact = $this->persistContact();
        $sync = $this->sync();
        $sync->apply($contact);
        $this->calls = [];

        // Lead closed or deleted: the agent's agenda must not keep it.
        $sync->clear($contact);

        self::assertCount(1, $this->calls);
        self::assertSame('DELETE', $this->calls[0]['method']);
        self::assertNull($contact->getRecallEventId());
    }

    public function testAnUnconfiguredCalendarIsSilentlySkipped(): void
    {
        $contact = $this->persistContact();

        $this->sync(configured: false)->apply($contact);

        self::assertSame([], $this->calls);
        self::assertNull($contact->getRecallEventId(), 'Nothing to clean up later.');
    }

    private function sync(bool $configured = true): RecallCalendarSync
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'test-token']));
            }
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return new MockResponse((string) json_encode(['id' => 'evt-1']));
        });

        $container = self::getContainer();

        return new RecallCalendarSync(
            new GoogleCalendarClient(
                $http,
                $container->get('logger'),
                $configured ? $this->keyFile : '',
                $configured ? 'agenda@relocation-in-paris.fr' : '',
            ),
            $this->em,
            $container->get('translator'),
            $container->get('router'),
            $container->get('logger'),
            'test_admin_prefix_1234567890abcdef',
        );
    }

    private function persistContact(): Contact
    {
        $agent = (new User())
            ->setEmail('agent@recall-sync-test.local')
            ->setFirstName('Agent')->setLastName('Test')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($agent);

        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setStatus(ContactStatus::InProgress)
            ->setNextStep(NextStep::Recontact)
            ->setRecontactChannel(RecontactChannel::Whatsapp)
            ->setRecallAt(new \DateTimeImmutable('+2 days 15:00'))
            ->setCreatedAt(new \DateTimeImmutable('-1 day 10:00'));
        $contact->setAssignedTo($agent);
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }
}
