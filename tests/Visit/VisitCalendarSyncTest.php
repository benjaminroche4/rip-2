<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Contact\Service\GoogleCalendarClient;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use App\Visit\Service\VisitCalendarSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Double écriture agenda d'une visite : un événement dans l'agenda central
 * (impersonation par défaut) et un jumeau dans l'agenda personnel de
 * l'assigné (impersonation = son email), tenus en phase au fil des
 * mutations et supprimés à l'annulation ou à la suppression.
 */
final class VisitCalendarSyncTest extends KernelTestCase
{
    private const CENTRAL = 'agenda@relocation-in-paris.fr';

    private EntityManagerInterface $em;

    /** @var list<array{method: string, url: string, body: string, sub: ?string}> */
    private array $apiCalls = [];

    /** @var list<string> JWT subjects of every token grant, in order. */
    private array $tokenSubjects = [];

    private string $keyFile;

    private int $eventSequence = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-calendar-test.local')->execute();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \assert(false !== $key);
        openssl_pkey_export($key, $pem);
        $this->keyFile = (string) tempnam(sys_get_temp_dir(), 'gcal-visit-');
        file_put_contents($this->keyFile, (string) json_encode([
            'client_email' => 'visio@service-account.test',
            'private_key' => $pem,
        ]));
        $this->resetCalls();
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    private function resetCalls(): void
    {
        $this->apiCalls = [];
        $this->tokenSubjects = [];
    }

    public function testCreationWithAnAssigneeWritesBothAgendas(): void
    {
        $visit = $this->persistVisit(assignee: $this->makeAssignee('Marie', 'Curie'));
        $sync = $this->sync();

        $sync->sync($visit);
        $this->em->flush();

        // Un grant JWT par agenda : le central puis celui de l'assignée.
        self::assertSame([self::CENTRAL, $visit->getAssignee()?->getEmail()], $this->tokenSubjects);
        self::assertCount(2, $this->apiCalls);
        self::assertSame('POST', $this->apiCalls[0]['method']);
        self::assertSame('POST', $this->apiCalls[1]['method']);
        self::assertSame(self::CENTRAL, $this->apiCalls[0]['sub']);
        self::assertSame($visit->getAssignee()?->getEmail(), $this->apiCalls[1]['sub']);

        // Titres : assignée dans le central, pas dans le personnel.
        $central = json_decode($this->apiCalls[0]['body'], true);
        $personal = json_decode($this->apiCalls[1]['body'], true);
        $reference = (string) $visit->getReference();
        self::assertSame('Visite · Famille Martin ('.$reference.') · Marie Curie', $central['summary']);
        self::assertSame('Visite · Famille Martin ('.$reference.')', $personal['summary']);

        // Payload partagé : adresse, créneau Europe/Paris (heure murale, pas
        // d'offset), description complète avec dossier, réf et lien BO.
        self::assertSame('12 rue de la Roquette, 75011 Paris', $central['location']);
        self::assertSame('Europe/Paris', $central['start']['timeZone']);
        self::assertSame('2026-09-10T14:00:00', $central['start']['dateTime']);
        self::assertSame('2026-09-10T14:45:00', $central['end']['dateTime']);
        $description = (string) $central['description'];
        self::assertStringContainsString('Dossier : Famille Martin ('.(string) $visit->getDossier()?->getReference().')', $description);
        self::assertStringContainsString('Type : Visite', $description);
        self::assertStringContainsString('Agent immobilier : Paul Agent (Agence Test) · 0611223344', $description);
        self::assertStringContainsString('Client présent : Oui', $description);
        self::assertStringContainsString('Annonce : https://www.seloger.com/annonce-1', $description);
        self::assertStringContainsString('Note : Code 1234', $description);
        self::assertStringContainsString('/test_admin_prefix_1234567890abcdef/admin/visites/'.$visit->getReference(), $description);
        self::assertStringStartsWith('http', substr($description, (int) strpos($description, 'Fiche visite : ') + 15), 'Absolute BO link.');

        // Dossier sans contact joignable : liste d'invités vide (toujours
        // envoyée pour qu'un PATCH garde la liste en phase), pas de Meet, et
        // jamais d'invités sur l'événement personnel.
        self::assertSame([], $central['attendees']);
        self::assertArrayNotHasKey('attendees', $personal);
        self::assertStringNotContainsString('hangoutsMeet', $this->apiCalls[0]['body']);

        // Le central notifie ses invités (sendUpdates=all), le personnel non.
        self::assertStringContainsString('sendUpdates=all', $this->apiCalls[0]['url']);
        self::assertStringContainsString('sendUpdates=none', $this->apiCalls[1]['url']);

        // Ids stockés pour patcher ensuite.
        self::assertSame('evt-1', $visit->getCalendarCentralEventId());
        self::assertSame('evt-2', $visit->getCalendarAssigneeEventId());
        self::assertSame($visit->getAssignee()?->getEmail(), $visit->getCalendarAssigneeEmail());
    }

    public function testDossierContactsAreInvitedOnTheCentralEventOnly(): void
    {
        $visit = $this->persistVisit(
            assignee: $this->makeAssignee('Marie', 'Curie'),
            personEmails: ['alice@client-visit-test.local', 'ALICE@client-visit-test.local', 'not-an-email', 'bob@client-visit-test.local'],
        );
        $sync = $this->sync();

        $sync->sync($visit);

        $central = json_decode($this->apiCalls[0]['body'], true);
        $personal = json_decode($this->apiCalls[1]['body'], true);

        // Invités : les contacts du dossier avec email valide, dédupliqués,
        // sur le central uniquement, et Google notifie (sendUpdates=all).
        self::assertSame(
            [['email' => 'alice@client-visit-test.local'], ['email' => 'bob@client-visit-test.local']],
            $central['attendees'],
        );
        self::assertArrayNotHasKey('attendees', $personal);
        self::assertStringContainsString('sendUpdates=all', $this->apiCalls[0]['url']);

        // La description du central devient visible client : rien d'interne
        // (préfixe admin caché, note, référence du dossier). L'événement
        // personnel garde la version interne complète.
        $centralDescription = (string) $central['description'];
        self::assertStringNotContainsString('test_admin_prefix', $centralDescription);
        self::assertStringNotContainsString('Note :', $centralDescription);
        self::assertStringNotContainsString('Code 1234', $centralDescription);
        self::assertStringNotContainsString((string) $visit->getDossier()?->getReference(), $centralDescription);
        self::assertStringContainsString('Agent immobilier : Paul Agent (Agence Test) · 0611223344', $centralDescription);
        self::assertStringContainsString('Annonce : https://www.seloger.com/annonce-1', $centralDescription);
        self::assertStringContainsString('test_admin_prefix', (string) $personal['description']);
        self::assertStringContainsString('Note : Code 1234', (string) $personal['description']);

        // Une mutation ultérieure (PATCH) renvoie la liste d'invités : les
        // attendees survivent aux updates et aux réassignations.
        $this->resetCalls();
        $visit->setAssignee($this->makeAssignee('Paul', 'Langevin'));
        $sync->sync($visit);
        $patch = $this->apiCalls[0];
        self::assertSame('PATCH', $patch['method']);
        self::assertStringContainsString('sendUpdates=all', $patch['url']);
        $patched = json_decode($patch['body'], true);
        self::assertSame(
            [['email' => 'alice@client-visit-test.local'], ['email' => 'bob@client-visit-test.local']],
            $patched['attendees'],
        );
    }

    public function testNobodyIsInvitedWhenTheClientDoesNotAttend(): void
    {
        // Visite sans le client (état des lieux, repérage par l'agent) : pas
        // d'invitation Google aux contacts du dossier, et la description du
        // central garde sa version interne complète.
        $visit = $this->persistVisit(
            assignee: $this->makeAssignee('Marie', 'Curie'),
            personEmails: ['alice@client-visit-test.local'],
        );
        $visit->setClientPresent(false);
        $this->em->flush();

        $this->sync()->sync($visit);

        $central = json_decode($this->apiCalls[0]['body'], true);
        self::assertSame([], $central['attendees']);
        self::assertStringContainsString('Note : Code 1234', (string) $central['description']);
        self::assertStringContainsString('test_admin_prefix', (string) $central['description']);
    }

    public function testAnAutonomousVisitOnlyFeedsTheCentralAgenda(): void
    {
        $visit = $this->persistVisit(assignee: null);
        $sync = $this->sync();

        $sync->sync($visit);

        self::assertCount(1, $this->apiCalls);
        self::assertSame(self::CENTRAL, $this->apiCalls[0]['sub']);
        $payload = json_decode($this->apiCalls[0]['body'], true);
        self::assertSame('Visite · Famille Martin ('.(string) $visit->getReference().') · Autonome', $payload['summary']);
        self::assertNull($visit->getCalendarAssigneeEventId());
        self::assertNull($visit->getCalendarAssigneeEmail());
    }

    public function testReassignmentMovesThePersonalEventToTheNewAgenda(): void
    {
        $marie = $this->makeAssignee('Marie', 'Curie');
        $visit = $this->persistVisit(assignee: $marie);
        $sync = $this->sync();
        $sync->sync($visit);
        $this->resetCalls();

        $paul = $this->makeAssignee('Paul', 'Langevin');
        $visit->setAssignee($paul);
        $sync->sync($visit);

        // Patch du central (titre mis à jour), delete chez Marie, insert
        // chez Paul.
        $byMethod = array_map(static fn (array $c): string => $c['method'].' '.$c['sub'], $this->apiCalls);
        self::assertSame([
            'PATCH '.self::CENTRAL,
            'DELETE '.$marie->getEmail(),
            'POST '.$paul->getEmail(),
        ], $byMethod);
        self::assertStringContainsString('evt-2', $this->apiCalls[1]['url'], 'The old personal event is the one deleted.');
        $central = json_decode($this->apiCalls[0]['body'], true);
        self::assertStringContainsString('· Paul Langevin', (string) $central['summary']);
        self::assertSame('evt-3', $visit->getCalendarAssigneeEventId());
        self::assertSame($paul->getEmail(), $visit->getCalendarAssigneeEmail());
    }

    public function testCancellationDropsBothEvents(): void
    {
        $visit = $this->persistVisit(assignee: $this->makeAssignee('Marie', 'Curie'));
        $sync = $this->sync();
        $sync->sync($visit);
        $assigneeEmail = (string) $visit->getAssignee()?->getEmail();
        $this->resetCalls();

        $visit->setStatus(VisitStatus::Cancelled);
        $sync->sync($visit);

        $byMethod = array_map(static fn (array $c): string => $c['method'].' '.$c['sub'], $this->apiCalls);
        self::assertSame(['DELETE '.self::CENTRAL, 'DELETE '.$assigneeEmail], $byMethod);
        self::assertNull($visit->getCalendarCentralEventId());
        self::assertNull($visit->getCalendarAssigneeEventId());
        self::assertNull($visit->getCalendarAssigneeEmail());
    }

    public function testForgetRemovesBothEventsBeforeDeletion(): void
    {
        $visit = $this->persistVisit(assignee: $this->makeAssignee('Marie', 'Curie'));
        $sync = $this->sync();
        $sync->sync($visit);
        $assigneeEmail = (string) $visit->getAssignee()?->getEmail();
        $this->resetCalls();

        $sync->forget($visit);

        $byMethod = array_map(static fn (array $c): string => $c['method'].' '.$c['sub'], $this->apiCalls);
        self::assertSame(['DELETE '.self::CENTRAL, 'DELETE '.$assigneeEmail], $byMethod);
        self::assertNull($visit->getCalendarCentralEventId());
        self::assertNull($visit->getCalendarAssigneeEventId());
    }

    public function testANonGonePatchFailureNeverRecreatesTheEvents(): void
    {
        $visit = $this->persistVisit(assignee: $this->makeAssignee('Marie', 'Curie'));
        $this->sync()->sync($visit);
        $this->resetCalls();

        // Panne transitoire (500) au PATCH : contrairement à un 404, les
        // événements existent peut-être encore. Aucun POST de recréation ne
        // doit partir, et les ids stockés survivent pour le prochain essai.
        $this->sync(patchStatus: 500)->sync($visit);

        $methods = array_map(static fn (array $c): string => $c['method'], $this->apiCalls);
        self::assertNotContains('POST', $methods, 'A failed PATCH must never be followed by a duplicate POST.');
        self::assertSame('evt-1', $visit->getCalendarCentralEventId());
        self::assertSame('evt-2', $visit->getCalendarAssigneeEventId());
    }

    public function testADeleteFailureKeepsTheEventIdsForRetry(): void
    {
        $visit = $this->persistVisit(assignee: $this->makeAssignee('Marie', 'Curie'));
        $this->sync()->sync($visit);
        $assigneeEmail = (string) $visit->getAssignee()?->getEmail();
        $this->resetCalls();

        // Annulation pendant une panne réseau : l'action métier aboutit
        // (aucune exception), mais les ids restent en place pour que la
        // prochaine mutation retente la suppression.
        $visit->setStatus(VisitStatus::Cancelled);
        $this->sync(deleteStatus: 500)->sync($visit);
        self::assertSame('evt-1', $visit->getCalendarCentralEventId());
        self::assertSame('evt-2', $visit->getCalendarAssigneeEventId());
        self::assertSame($assigneeEmail, $visit->getCalendarAssigneeEmail());

        // Un 404 en revanche vaut "déjà disparu" : les ids se nettoient.
        $this->sync(deleteStatus: 404)->sync($visit);
        self::assertNull($visit->getCalendarCentralEventId());
        self::assertNull($visit->getCalendarAssigneeEventId());
        self::assertNull($visit->getCalendarAssigneeEmail());
    }

    public function testAFailedOldAgendaCleanupPostponesTheReassignment(): void
    {
        $marie = $this->makeAssignee('Marie', 'Curie');
        $visit = $this->persistVisit(assignee: $marie);
        $this->sync()->sync($visit);
        $this->resetCalls();

        // Réassignation pendant une panne du DELETE : l'ancienne paire
        // id/email est conservée (retry au prochain sync) et aucun événement
        // n'est créé chez le nouvel assigné (il écraserait la paire).
        $paul = $this->makeAssignee('Paul', 'Langevin');
        $visit->setAssignee($paul);
        $this->sync(deleteStatus: 500)->sync($visit);

        $byMethod = array_map(static fn (array $c): string => $c['method'].' '.$c['sub'], $this->apiCalls);
        self::assertSame(['PATCH '.self::CENTRAL, 'DELETE '.$marie->getEmail()], $byMethod);
        self::assertSame('evt-2', $visit->getCalendarAssigneeEventId());
        self::assertSame($marie->getEmail(), $visit->getCalendarAssigneeEmail());
    }

    public function testAnUnconfiguredCalendarIsANoOp(): void
    {
        $visit = $this->persistVisit(assignee: $this->makeAssignee('Marie', 'Curie'));
        $sync = $this->sync(configured: false);

        $sync->sync($visit);
        $sync->forget($visit);
        self::assertNull($sync->findAssigneeBusyInterval('marie@visit-calendar-test.local', new \DateTimeImmutable('+1 day'), 30));

        self::assertSame([], $this->apiCalls);
        self::assertNull($visit->getCalendarCentralEventId());
    }

    public function testBusyIntervalDetectionOverlapAndExactSlotTolerance(): void
    {
        $sync = $this->sync(busy: [
            ['start' => '2026-09-10T12:00:00Z', 'end' => '2026-09-10T12:30:00Z'],
        ]);

        // 14h00-14h30 Paris = 12h00-12h30 UTC en septembre : chevauchement.
        $busy = $sync->findAssigneeBusyInterval('marie@visit-calendar-test.local', new \DateTimeImmutable('2026-09-10 14:15'), 30);
        self::assertNotNull($busy);
        self::assertSame('2026-09-10 14:00', $busy['start']->format('Y-m-d H:i'));
        self::assertSame('2026-09-10 14:30', $busy['end']->format('Y-m-d H:i'));

        // Le même intervalle, quand il correspond exactement au créneau déjà
        // stocké de la visite éditée, est son propre miroir : toléré.
        self::assertNull($sync->findAssigneeBusyInterval(
            'marie@visit-calendar-test.local',
            new \DateTimeImmutable('2026-09-10 14:15'),
            30,
            currentStart: new \DateTimeImmutable('2026-09-10 14:00'),
            currentDurationMinutes: 30,
        ));
    }

    public function testBusyLookupFailuresNeverBlock(): void
    {
        // API en erreur : disponibilité inconnue, jamais un faux blocage.
        $sync = $this->sync(busyStatus: 500);

        self::assertNull($sync->findAssigneeBusyInterval('marie@visit-calendar-test.local', new \DateTimeImmutable('2026-09-10 14:15'), 30));
    }

    /**
     * @param list<array{start: string, end: string}> $busy
     */
    private function sync(bool $configured = true, array $busy = [], int $busyStatus = 200, int $patchStatus = 200, int $deleteStatus = 200): VisitCalendarSync
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($busy, $busyStatus, $patchStatus, $deleteStatus): MockResponse {
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                parse_str((string) $options['body'], $tokenBody);
                $claims = json_decode((string) base64_decode(strtr(explode('.', (string) $tokenBody['assertion'])[1], '-_', '+/'), true), true);
                $sub = (string) ($claims['sub'] ?? '');
                $this->tokenSubjects[] = $sub;
                // Un token distinct par sujet : chaque appel API révèle sous
                // quelle impersonation il part.
                return new MockResponse((string) json_encode(['access_token' => 'token-for-'.$sub]));
            }

            $auth = '';
            foreach ((array) ($options['normalized_headers']['authorization'] ?? []) as $header) {
                $auth = (string) $header;
            }
            $sub = 1 === preg_match('/token-for-(\S+)/', $auth, $m) ? $m[1] : null;
            $this->apiCalls[] = [
                'method' => $method,
                'url' => $url,
                'body' => (string) ($options['body'] ?? ''),
                'sub' => $sub,
            ];

            if (str_contains($url, '/freeBusy')) {
                if ($busyStatus >= 400) {
                    return new MockResponse('', ['http_code' => $busyStatus]);
                }

                return new MockResponse((string) json_encode(['calendars' => ['primary' => ['busy' => $busy]]]));
            }

            if ('DELETE' === $method) {
                if ($deleteStatus >= 400) {
                    return new MockResponse('', ['http_code' => $deleteStatus]);
                }

                return new MockResponse('');
            }
            if ('PATCH' === $method && 1 === preg_match('#/events/([^/?]+)#', $url, $m)) {
                if ($patchStatus >= 400) {
                    return new MockResponse('', ['http_code' => $patchStatus]);
                }
                // Comme la vraie API : un patch renvoie le même id.
                return new MockResponse((string) json_encode(['id' => rawurldecode($m[1])]));
            }
            ++$this->eventSequence;

            return new MockResponse((string) json_encode(['id' => 'evt-'.$this->eventSequence]));
        });

        $container = self::getContainer();

        return new VisitCalendarSync(
            new GoogleCalendarClient(
                $http,
                $container->get('logger'),
                $configured ? $this->keyFile : '',
                $configured ? self::CENTRAL : '',
            ),
            $container->get('translator'),
            $container->get('router'),
            $container->get('logger'),
            'test_admin_prefix_1234567890abcdef',
        );
    }

    private function makeAssignee(string $firstName, string $lastName): User
    {
        $user = (new User())
            ->setEmail(strtolower($firstName).'+'.bin2hex(random_bytes(4)).'@visit-calendar-test.local')
            ->setFirstName($firstName)->setLastName($lastName)
            // Volontairement sans la fonction "Agent de visite" : le service
            // ne filtre pas, et la donnée polluerait les tests du pool
            // d'assignables (findVisitAgents est global).
            ->setRoles(['ROLE_STAFF', 'ROLE_SECTION_VISITS'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param list<string> $personEmails
     */
    private function persistVisit(?User $assignee, array $personEmails = []): Visit
    {
        $agency = (new Agency())->setName('Agence Test')->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agency);
        $agent = (new RealEstateAgent())
            ->setFirstName('Paul')->setLastName('Agent')
            ->setAgency($agency)
            ->setPhone('0611223344')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);

        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        foreach ($personEmails as $i => $email) {
            $dossier->addPerson((new DossierPerson())
                ->setRole(0 === $i ? DossierPersonRole::TENANT : DossierPersonRole::FOLLOW_UP)
                ->setFirstName('Client'.$i)
                ->setLastName('Martin')
                ->setEmail($email)
                ->setPrimaryContact(0 === $i));
        }
        $this->em->persist($dossier);

        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt(new \DateTimeImmutable('2026-09-10 14:00'))
            ->setDurationMinutes(45)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setAgent($agent)
            ->setAssignee($assignee)
            ->setClientPresent(true)
            ->setListingUrl('https://www.seloger.com/annonce-1')
            ->setNote('Code 1234')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }
}
