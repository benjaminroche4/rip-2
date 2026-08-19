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
            '/fr/'.self::WRONG_PREFIX.'/admin/visites/'.$visit->getReference().'/statut',
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
            '/fr/'.self::WRONG_PREFIX.'/admin/visites/'.$visit->getReference().'/compte-rendu',
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
        // Statut Effectuée : le formulaire de compte-rendu (où se scrape le
        // token CSRF) n'apparaît sur la fiche qu'une fois la visite faite.
        $visit = $this->persistVisit(status: VisitStatus::Done);

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
        $visit = $this->persistVisit(status: VisitStatus::Done);

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

    public function testDoneStatusRedirectCarriesTheReportAnchor(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/statut',
            ['_token' => $this->statusToken($visit), 'status' => 'done'],
        );

        self::assertResponseStatusCodeSame(303);
        // Effectuée atterrit directement sur le formulaire de compte-rendu.
        self::assertStringEndsWith('#visit-report', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testNonDoneStatusRedirectCarriesNoAnchor(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/statut',
            ['_token' => $this->statusToken($visit), 'status' => 'cancelled'],
        );

        self::assertResponseStatusCodeSame(303);
        self::assertStringNotContainsString('#', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testOverdueBannerShowsForAPlannedPastVisit(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit('-1 day 10:30');

        $crawler = $this->client->request('GET', $this->visitUrl($visit));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="visit-overdue-banner"]'));
        // Les deux issues proposées par le bandeau : Effectuée et Annulée.
        self::assertCount(1, $crawler->filter('[data-testid="visit-overdue-done"]'));
        self::assertCount(1, $crawler->filter('[data-testid="visit-overdue-cancelled"]'));
    }

    public function testOverdueBannerIsAbsentForFutureDoneAndCancelledVisits(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);

        foreach ([
            $this->persistVisit('+1 day 10:30'),
            $this->persistVisit('-1 day 10:30', VisitStatus::Done),
            $this->persistVisit('-1 day 10:30', VisitStatus::Cancelled),
        ] as $visit) {
            $crawler = $this->client->request('GET', $this->visitUrl($visit));
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[data-testid="visit-overdue-banner"]'), 'No banner expected for '.$visit->getReference());
        }
    }

    public function testStatusActionsNeverOfferTheCurrentStatus(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);

        $expected = [
            ['visit' => $this->persistVisit(status: VisitStatus::Planned), 'absent' => 'planned', 'present' => ['done', 'cancelled']],
            ['visit' => $this->persistVisit(status: VisitStatus::Done), 'absent' => 'done', 'present' => ['planned', 'cancelled']],
            ['visit' => $this->persistVisit(status: VisitStatus::Cancelled), 'absent' => 'cancelled', 'present' => ['planned', 'done']],
        ];
        foreach ($expected as $case) {
            $crawler = $this->client->request('GET', $this->visitUrl($case['visit']));
            self::assertResponseIsSuccessful();
            $actions = $crawler->filter('[data-testid="visit-status-actions"]');
            // Le statut courant reste visible, coloré et non soumis (span
            // aria-current) ; les deux autres sont des bascules en form.
            $current = $actions->filter('[data-testid="visit-status-'.$case['absent'].'"]');
            self::assertCount(1, $current);
            self::assertSame('span', $current->nodeName());
            self::assertSame('true', $current->attr('aria-current'));
            foreach ($case['present'] as $status) {
                $button = $actions->filter('[data-testid="visit-status-'.$status.'"]');
                self::assertCount(1, $button);
                self::assertSame('button', $button->nodeName());
            }
        }
    }

    public function testAFilledReportRendersLockedWithAPencilAndTheFormStaysInTheDom(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(status: VisitStatus::Done);
        $visit->setReport("Points forts :\nTrès lumineux, client conquis.");
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->visitUrl($visit));

        self::assertResponseIsSuccessful();
        // Lecture verrouillée : le texte rendu + le stylo d'édition.
        $display = $crawler->filter('[data-testid="visit-report-display"]');
        self::assertCount(1, $display);
        self::assertStringContainsString('client conquis', $display->text());
        self::assertCount(1, $crawler->filter('[data-testid="visit-report-edit"]'));
        // L'éditeur reste dans le DOM (autosave + tests crawler), juste masqué.
        $textarea = $crawler->filter('[data-testid="visit-report-text"]');
        self::assertCount(1, $textarea);
        self::assertStringContainsString('hidden', (string) $textarea->attr('class'));
        self::assertCount(1, $crawler->filter('[data-testid="visit-report-form"] textarea[name="report"]'));
    }

    public function testAnEmptyReportRendersDirectlyInEditMode(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(status: VisitStatus::Done);

        $crawler = $this->client->request('GET', $this->visitUrl($visit));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="visit-report-display"]'));
        self::assertCount(0, $crawler->filter('[data-testid="visit-report-edit"]'));
        $textarea = $crawler->filter('[data-testid="visit-report-text"]');
        self::assertCount(1, $textarea);
        self::assertStringNotContainsString('hidden', (string) $textarea->attr('class'));
    }

    public function testAFeelingChipAutosaveSubmissionPersistsTheFeeling(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(status: VisitStatus::Done);
        $visit->setReport('Retour déjà en base.');
        $this->em->flush();

        // Le clic sur une chip soumet le formulaire tel quel (pas de bouton
        // Enregistrer) : même soumission que requestSubmit() côté navigateur.
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        $form = $crawler->filter('[data-testid="visit-report-form"]')->form();
        $form['feeling']->select('hot');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame(ClientFeeling::Hot, $reloaded->getClientFeeling());
        // Le textarea voyage dans le même POST : le retour agent survit.
        self::assertSame('Retour déjà en base.', $reloaded->getReport());
    }

    private function visitUrl(Visit $visit): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getReference();
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

    private function persistVisit(string $when = '+1 day 10:30', VisitStatus $status = VisitStatus::Planned): Visit
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
            ->setScheduledAt(new \DateTimeImmutable($when))
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setStatus($status)
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
