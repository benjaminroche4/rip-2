<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Domain\ClientFeeling;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP contract of the visit status and report POST routes: hidden-prefix
 * 404 for anonymous probes, section role required, CSRF mandatory, and the
 * DTO validation (unknown status, report over 5000 chars) answering 400.
 */
final class VisitStatusReportAccessTest extends WebTestCase
{
    private const WRONG_PREFIX = '00000000000000000000000000000000';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-status-test.local')->execute();
    }

    public function testItReturns404ForAnonymousStatusPostOnAWrongPrefix(): void
    {
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            '/fr/'.self::WRONG_PREFIX.'/admin/visites/'.$visit->getId().'/statut',
            ['_token' => 'x', 'status' => 'done'],
        );

        // Wrong prefix must 404 before any auth challenge (no login redirect).
        self::assertResponseStatusCodeSame(404);
    }

    public function testItReturns404ForAnonymousReportPostOnAWrongPrefix(): void
    {
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            '/fr/'.self::WRONG_PREFIX.'/admin/visites/'.$visit->getId().'/compte-rendu',
            ['_token' => 'x', 'report' => 'Hello'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testItRefusesAStatusChangeWithoutTheVisitsSection(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/statut',
            ['_token' => 'irrelevant', 'status' => 'done'],
        );

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame(VisitStatus::Planned, $this->em->find(Visit::class, $visit->getId())->getStatus());
    }

    public function testItUpdatesTheStatusWithTheSectionRoleAndAValidToken(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/statut',
            ['_token' => $this->statusToken($visit), 'status' => 'done'],
        );

        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertSame(VisitStatus::Done, $this->em->find(Visit::class, $visit->getId())->getStatus());
    }

    public function testItRejectsAStatusChangeWithAnInvalidCsrfToken(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/statut',
            ['_token' => 'not-a-valid-token', 'status' => 'done'],
        );

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame(VisitStatus::Planned, $this->em->find(Visit::class, $visit->getId())->getStatus());
    }

    public function testItRejectsAnUnknownStatusWith400(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/statut',
            ['_token' => $this->statusToken($visit), 'status' => 'teleported'],
        );

        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertSame(VisitStatus::Planned, $this->em->find(Visit::class, $visit->getId())->getStatus());
    }

    public function testItPersistsAValidReportAndFeeling(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/compte-rendu',
            ['_token' => $this->reportToken($visit), 'report' => 'Bel appartement, client conquis.', 'feeling' => 'hot'],
        );

        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame('Bel appartement, client conquis.', $reloaded->getReport());
        self::assertSame(ClientFeeling::Hot, $reloaded->getClientFeeling());
    }

    public function testItRejectsAReportOver5000CharactersWith400(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/compte-rendu',
            ['_token' => $this->reportToken($visit), 'report' => str_repeat('a', 5001), 'feeling' => ''],
        );

        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getReport());
    }

    public function testItRejectsAReportWithAnInvalidCsrfToken(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/compte-rendu',
            ['_token' => 'not-a-valid-token', 'report' => 'Hello'],
        );

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getReport());
    }

    public function testItRefusesAReportWithoutTheVisitsSection(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/compte-rendu',
            ['_token' => 'irrelevant', 'report' => 'Hello'],
        );

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getReport());
    }

    private function visitUrl(Visit $visit): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getId();
    }

    /** Scrapes the CSRF token of the status form on the show page. */
    private function statusToken(Visit $visit): string
    {
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form[action$="/statut"] input[name="_token"]')->first()->attr('value');
    }

    /** Scrapes the CSRF token of the report form on the show page. */
    private function reportToken(Visit $visit): string
    {
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-testid="visit-report-form"] input[name="_token"]')->attr('value');
    }

    private function persistVisit(): Visit
    {
        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);

        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt(new \DateTimeImmutable('+1 day 10:30'))
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
            ->setEmail(bin2hex(random_bytes(4)).'@visit-status-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
