<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierEvent;
use App\Visit\Domain\ClientDecision;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Suivi du compte-rendu de visite : toasts "Enregistré" post-redirect sur
 * chaque autosave, horodatage de la décision client (posé, mis à jour,
 * effacé, affiché), badge ambre d'échéance de réflexion dépassée (fiche et
 * liste), et entrées du fil du dossier sur les transitions uniquement.
 */
final class VisitReportFollowupTest extends WebTestCase
{
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
        $this->em->createQuery('DELETE FROM '.DossierEvent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-followup-test.local')->execute();
    }

    public function testEveryReportAutosavePushesASavedToast(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        // Retour de l'agent.
        $this->client->request('POST', $this->visitUrl($visit).'/compte-rendu', [
            '_token' => $this->token($visit, 'visit-report-form'),
            'report' => 'Bien lumineux, client conquis.',
            'feeling' => '',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->assertSavedToastOnRedirect();

        // Note client.
        $this->client->request('POST', $this->visitUrl($visit).'/note-client', [
            '_token' => $this->token($visit, 'visit-client-note-form'),
            'clientNote' => 'Voici notre retour.',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->assertSavedToastOnRedirect();

        // Décision, échéance, origine du refus (même endpoint).
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'thinking',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->assertSavedToastOnRedirect();

        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            // Dans la borne des deux ans acceptée par le contrôleur.
            'deadline' => (new \DateTimeImmutable('+1 year'))->format('Y-m-d'),
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->assertSavedToastOnRedirect();

        // Issue de candidature (après positionnement).
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'positioning',
        ]);
        $this->client->request('POST', $this->visitUrl($visit).'/issue-candidature', [
            '_token' => $this->outcomeToken($visit),
            'outcome' => 'accepted',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->assertSavedToastOnRedirect();
    }

    public function testTheDecisionTimestampIsSetUpdatedAndClearedWithTheDecision(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'thinking',
        ]);
        $this->em->clear();
        $first = $this->em->find(Visit::class, $visit->getId())->getClientDecisionAt();
        self::assertNotNull($first, 'A new decision stamps the timestamp.');

        // Changement de décision : l'horodatage bouge.
        $this->em->createQuery('UPDATE '.Visit::class." v SET v.clientDecisionAt = :old WHERE v.id = :id")
            ->setParameter('old', new \DateTimeImmutable('2026-01-01 10:00'))
            ->setParameter('id', $visit->getId())
            ->execute();
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'refused',
        ]);
        $this->em->clear();
        $updated = $this->em->find(Visit::class, $visit->getId())->getClientDecisionAt();
        self::assertNotNull($updated);
        self::assertGreaterThan(new \DateTimeImmutable('2026-01-01 10:00'), $updated, 'A decision change refreshes the timestamp.');

        // "Depuis le" s'affiche tant qu'une décision est active.
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertCount(1, $crawler->filter('[data-testid="visit-decision-since"]'));

        // Toggle-off : décision effacée, horodatage remis à zéro.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => '',
        ]);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getClientDecisionAt());
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertCount(0, $crawler->filter('[data-testid="visit-decision-since"]'));
    }

    public function testAnOverdueThinkingDeadlineShowsTheAmberBadgeOnTheShowPageAndTheList(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        // Visite du jour (donc dans la liste "à venir"), effectuée, client
        // en réflexion avec une échéance dépassée.
        $visit = $this->persistVisit(scheduledAt: new \DateTimeImmutable('today 08:00'));
        $visit->setClientDecision(ClientDecision::Thinking)
            ->setClientDecisionAt(new \DateTimeImmutable('-5 days'))
            ->setDecisionDeadline(new \DateTimeImmutable('-2 days'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="visit-deadline-overdue"]'), 'The report card flags the overdue deadline.');

        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="visit-deadline-overdue"]'), 'The visit card in the list flags it too.');
    }

    public function testAFutureDeadlineShowsNoOverdueBadge(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();
        $visit->setClientDecision(ClientDecision::Thinking)
            ->setClientDecisionAt(new \DateTimeImmutable())
            ->setDecisionDeadline(new \DateTimeImmutable('+3 days'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="visit-deadline-overdue"]'));
    }

    public function testDossierEventsAreLoggedOnTransitionsOnly(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(status: VisitStatus::Planned);

        // (a) Visite marquée effectuée : une entrée, pas de doublon au
        // re-POST (le token CSRF reste valable en session).
        $statusToken = $this->statusToken($visit);
        $this->client->request('POST', $this->visitUrl($visit).'/statut', ['_token' => $statusToken, 'status' => 'done']);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('POST', $this->visitUrl($visit).'/statut', ['_token' => $statusToken, 'status' => 'done']);
        self::assertSame(1, $this->countEvents('visit_done'));

        // (c) Refus : une entrée, un re-POST forgé de la même valeur n'en
        // crée pas d'autre; l'origine du refus complète le fil.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', ['_token' => $this->decisionToken($visit), 'decision' => 'refused']);
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', ['_token' => $this->decisionToken($visit), 'decision' => 'refused']);
        self::assertSame(1, $this->countEvents('visit_client_refused'));
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', ['_token' => $this->decisionToken($visit), 'origin' => 'landlord']);
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', ['_token' => $this->decisionToken($visit), 'origin' => 'landlord']);
        self::assertSame(1, $this->countEvents('visit_refusal_origin'));

        // (d) Positionnement.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', ['_token' => $this->decisionToken($visit), 'decision' => 'positioning']);
        self::assertSame(1, $this->countEvents('visit_client_positioned'));

        // (e) Issue de candidature : transition vers Validé, pas de doublon,
        // et le retour "en attente" (toggle-off) ne crée rien.
        $this->client->request('POST', $this->visitUrl($visit).'/issue-candidature', ['_token' => $this->outcomeToken($visit), 'outcome' => 'accepted']);
        $this->client->request('POST', $this->visitUrl($visit).'/issue-candidature', ['_token' => $this->outcomeToken($visit), 'outcome' => 'accepted']);
        self::assertSame(1, $this->countEvents('visit_application_outcome'));
        $this->client->request('POST', $this->visitUrl($visit).'/issue-candidature', ['_token' => $this->outcomeToken($visit), 'outcome' => '']);
        self::assertSame(1, $this->countEvents('visit_application_outcome'));
    }

    private function countEvents(string $kind): int
    {
        $this->em->clear();

        return $this->em->getRepository(DossierEvent::class)->count(['kind' => $kind]);
    }

    private function assertSavedToastOnRedirect(): void
    {
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Modifications enregistrées.',
            $crawler->filter('[data-testid="toasts"]')->text(),
            'The Admin/Toasts pile shows the saved confirmation after the redirect.',
        );
    }

    private function token(Visit $visit, string $formTestId): string
    {
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-testid="'.$formTestId.'"] input[name="_token"]')->attr('value');
    }

    /** Scrapes the CSRF token of the decision chips. */
    private function decisionToken(Visit $visit): string
    {
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-testid="visit-client-decision"] input[name="_token"]')->first()->attr('value');
    }

    /** Scrapes the CSRF token of the application outcome chips. */
    private function outcomeToken(Visit $visit): string
    {
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-testid="visit-application-outcome"] input[name="_token"]')->first()->attr('value');
    }

    /** Scrapes the CSRF token of the overdue banner status forms. */
    private function statusToken(Visit $visit): string
    {
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-testid="visit-overdue-banner"] input[name="_token"]')->first()->attr('value');
    }

    private function visitUrl(Visit $visit): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getReference();
    }

    private function persistVisit(VisitStatus $status = VisitStatus::Done, ?\DateTimeImmutable $scheduledAt = null): Visit
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
            ->setScheduledAt($scheduledAt ?? new \DateTimeImmutable('-1 day 10:30'))
            ->setStatus($status)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    private function loginAs(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-followup-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
