<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Domain\ApplicationOutcome;
use App\Visit\Domain\ClientDecision;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Retour client" and "Issue de la candidature" blocks of the report card:
 * chip autosave with toggle-off, the outcome block only living while the
 * client positions themselves (and reset otherwise), and the report card
 * sitting above the property card.
 */
final class VisitClientDecisionTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-decision-test.local')->execute();
    }

    public function testTheReportCardSitsAboveThePropertyCard(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $visit = $this->persistVisit(VisitStatus::Done);

        $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $report = strpos($html, 'data-testid="visit-report-card"');
        $property = strpos($html, 'data-testid="visit-property-card"');
        self::assertNotFalse($report);
        self::assertNotFalse($property);
        self::assertLessThan($property, $report, 'The report card renders before the property card.');
        // Les trois blocs attendus : retour agent (avec photos after), retour
        // client; l'issue de candidature attend un client positionné.
        self::assertStringContainsString('data-testid="visit-report-agent-block"', $html);
        self::assertStringContainsString('data-testid="visit-report-photos"', $html);
        self::assertStringContainsString('data-testid="visit-client-decision"', $html);
        self::assertStringNotContainsString('data-testid="visit-application-outcome"', $html);
    }

    public function testDecisionIsPersistedAndTogglesOff(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done);

        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'refused',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame(ClientDecision::Refused, $reloaded->getClientDecision());
        self::assertNotNull($reloaded->getUpdatedAt(), 'The editor snapshot is stamped.');

        // Toggle-off : la chip active renvoie une valeur vide.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => '',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getClientDecision());
    }

    public function testUnknownDecisionAnswers400(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done);

        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'maybe',
        ]);

        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getClientDecision());
    }

    public function testOutcomeBlockOnlyShowsForAPositioningClient(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done);

        // Positionnement : le bloc issue de la candidature apparaît, en
        // attente (aucune chip active).
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'positioning',
        ]);
        $crawler = $this->client->followRedirect();
        // La zone compte-rendu peut porter plusieurs variantes de design
        // (exploration uidotsh) : on regarde la première occurrence.
        $outcome = $crawler->filter('[data-testid="visit-application-outcome"]');
        self::assertGreaterThan(0, \count($outcome));
        $outcome = $outcome->first();
        self::assertSame('false', $outcome->filter('[data-testid="visit-application-accepted"]')->first()->attr('aria-pressed'));
        self::assertSame('false', $outcome->filter('[data-testid="visit-application-refused"]')->first()->attr('aria-pressed'));
    }

    public function testOutcomeIsPersistedTogglesOffAndResetsWhenTheDecisionChanges(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done, ClientDecision::Positioning);

        // Validé par le bailleur.
        $this->client->request('POST', $this->visitUrl($visit).'/issue-candidature', [
            '_token' => $this->outcomeToken($visit),
            'outcome' => 'accepted',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertSame(ApplicationOutcome::Accepted, $this->em->find(Visit::class, $visit->getId())->getApplicationOutcome());

        // Toggle-off : retour en attente.
        $this->client->request('POST', $this->visitUrl($visit).'/issue-candidature', [
            '_token' => $this->outcomeToken($visit),
            'outcome' => '',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getApplicationOutcome());

        // Issue re-posée puis décision client changée : l'issue est remise à
        // null côté serveur (un reliquat n'a plus de sens).
        $this->client->request('POST', $this->visitUrl($visit).'/issue-candidature', [
            '_token' => $this->outcomeToken($visit),
            'outcome' => 'refused',
        ]);
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'thinking',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame(ClientDecision::Thinking, $reloaded->getClientDecision());
        self::assertNull($reloaded->getApplicationOutcome());
    }

    public function testOutcomePostIsRefusedWhenTheClientHasNotPositioned(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        // Le token CSRF est lié à l'action, pas à la visite : celui scrappé
        // sur une visite positionnée vaut pour le POST forgé sur l'autre.
        $positioned = $this->persistVisit(VisitStatus::Done, ClientDecision::Positioning);
        $visit = $this->persistVisit(VisitStatus::Done, ClientDecision::Refused);

        $this->client->request('POST', $this->visitUrl($visit).'/issue-candidature', [
            '_token' => $this->outcomeToken($positioned),
            'outcome' => 'accepted',
        ]);

        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getApplicationOutcome());
    }

    public function testThinkingDeadlinePersistsClearsAndResetsWithTheDecision(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done, ClientDecision::Thinking);

        // Échéance posée depuis le champ date du bloc (autosave au change).
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'deadline' => '2026-09-15',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame('2026-09-15', $reloaded->getDecisionDeadline()?->format('Y-m-d'));
        self::assertNotNull($reloaded->getUpdatedAt(), 'The editor snapshot is stamped.');

        // Champ vidé : retour à l'état sans échéance.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'deadline' => '',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getDecisionDeadline());

        // Échéance re-posée puis décision changée : l'échéance est purgée.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'deadline' => '2026-09-15',
        ]);
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'positioning',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame(ClientDecision::Positioning, $reloaded->getClientDecision());
        self::assertNull($reloaded->getDecisionDeadline());
    }

    public function testDeadlineIsRefusedOutsideThinkingOrOnGarbage(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);

        // Hors "Réfléchit" : 400.
        $refused = $this->persistVisit(VisitStatus::Done, ClientDecision::Refused);
        $this->client->request('POST', $this->visitUrl($refused).'/retour-client', [
            '_token' => $this->decisionToken($refused),
            'deadline' => '2026-09-15',
        ]);
        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $refused->getId())->getDecisionDeadline());

        // Date illisible : 400.
        $thinking = $this->persistVisit(VisitStatus::Done, ClientDecision::Thinking);
        $this->client->request('POST', $this->visitUrl($thinking).'/retour-client', [
            '_token' => $this->decisionToken($thinking),
            'deadline' => 'not-a-date',
        ]);
        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $thinking->getId())->getDecisionDeadline());
    }

    public function testRefusalOriginPersistsTogglesOffAndResetsWithTheDecision(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done, ClientDecision::Refused);

        // Origine posée : le bailleur a refusé.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'origin' => 'landlord',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertSame(\App\Visit\Domain\RefusalOrigin::Landlord, $this->em->find(Visit::class, $visit->getId())->getRefusalOrigin());

        // Toggle-off : la chip active renvoie une valeur vide.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'origin' => '',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getRefusalOrigin());

        // Origine re-posée puis décision changée : l'origine est purgée
        // (et le compteur "biens refusés" ne compte que les refus dont
        // l'origine est le client).
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'origin' => 'client',
        ]);
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'thinking',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame(ClientDecision::Thinking, $reloaded->getClientDecision());
        self::assertNull($reloaded->getRefusalOrigin());
    }

    public function testRefusalOriginIsRefusedOutsideRefusedOrOnForgedValues(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);

        // Hors "Refuse" : 400.
        $thinking = $this->persistVisit(VisitStatus::Done, ClientDecision::Thinking);
        $this->client->request('POST', $this->visitUrl($thinking).'/retour-client', [
            '_token' => $this->decisionToken($thinking),
            'origin' => 'landlord',
        ]);
        self::assertResponseStatusCodeSame(400);

        // Valeur forgée : 400.
        $refused = $this->persistVisit(VisitStatus::Done, ClientDecision::Refused);
        $this->client->request('POST', $this->visitUrl($refused).'/retour-client', [
            '_token' => $this->decisionToken($refused),
            'origin' => 'stranger',
        ]);
        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $refused->getId())->getRefusalOrigin());

        // Aucun champ reconnu : 400 aussi.
        $this->client->request('POST', $this->visitUrl($refused).'/retour-client', [
            '_token' => $this->decisionToken($refused),
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testStateBannersFollowTheDecision(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);

        // Réfléchit sans échéance : bandeau titre seul.
        $thinking = $this->persistVisit(VisitStatus::Done, ClientDecision::Thinking);
        $crawler = $this->client->request('GET', $this->visitUrl($thinking));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="visit-thinking-banner"]'));
        self::assertCount(0, $crawler->filter('[data-testid="visit-thinking-banner-deadline"]'));
        // Le champ échéance vit dans le bloc Retour client.
        self::assertCount(1, $crawler->filter('[data-testid="visit-decision-deadline-input"]'));

        // Échéance posée : le sous-texte apparaît avec la date.
        $this->client->request('POST', $this->visitUrl($thinking).'/retour-client', [
            '_token' => $this->decisionToken($thinking),
            'deadline' => '2026-09-15',
        ]);
        $crawler = $this->client->followRedirect();
        $deadline = $crawler->filter('[data-testid="visit-thinking-banner-deadline"]');
        self::assertCount(1, $deadline);
        self::assertStringContainsString('15 septembre 2026', (string) $deadline->text());

        // Se positionne sans issue : bandeau "candidature déposée".
        $positioning = $this->persistVisit(VisitStatus::Done, ClientDecision::Positioning);
        $crawler = $this->client->request('GET', $this->visitUrl($positioning));
        self::assertCount(1, $crawler->filter('[data-testid="visit-positioning-banner"]'));
        self::assertCount(0, $crawler->filter('[data-testid="visit-thinking-banner"]'));

        // Une issue posée éteint le bandeau d'attente.
        $this->client->request('POST', $this->visitUrl($positioning).'/issue-candidature', [
            '_token' => $this->outcomeToken($positioning),
            'outcome' => 'accepted',
        ]);
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('[data-testid="visit-positioning-banner"]'));

        // Refuse : aucun des deux bandeaux, mais le sous-choix d'origine.
        $refused = $this->persistVisit(VisitStatus::Done, ClientDecision::Refused);
        $crawler = $this->client->request('GET', $this->visitUrl($refused));
        self::assertCount(0, $crawler->filter('[data-testid="visit-thinking-banner"]'));
        self::assertCount(0, $crawler->filter('[data-testid="visit-positioning-banner"]'));
        self::assertCount(1, $crawler->filter('[data-testid="visit-refusal-origin"]'));
        self::assertSame('false', $crawler->filter('[data-testid="visit-refusal-landlord"]')->first()->attr('aria-pressed'));
    }

    public function testDecisionAndOutcomeAreRefusedOnANonDoneVisit(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        // Le token CSRF est lié à l'action : celui scrappé sur une visite
        // Effectuée vaut pour le POST forgé sur la visite planifiée (dont la
        // fiche n'affiche pas le bloc compte-rendu).
        $done = $this->persistVisit(VisitStatus::Done, ClientDecision::Positioning);
        $planned = $this->persistVisit(VisitStatus::Planned);

        // Décision sur une visite planifiée : 400 (pas de compte-rendu sans
        // visite effectuée).
        $this->client->request('POST', $this->visitUrl($planned).'/retour-client', [
            '_token' => $this->decisionToken($done),
            'decision' => 'refused',
        ]);
        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $planned->getId())->getClientDecision());

        // Issue de candidature sur une visite annulée (reliquat forgé avec
        // un positionnement) : 400 aussi.
        $cancelled = $this->persistVisit(VisitStatus::Cancelled, ClientDecision::Positioning);
        $this->client->request('POST', $this->visitUrl($cancelled).'/issue-candidature', [
            '_token' => $this->outcomeToken($done),
            'outcome' => 'accepted',
        ]);
        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $cancelled->getId())->getApplicationOutcome());
    }

    public function testRetrogradingADoneVisitPurgesTheClientReportFields(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done, ClientDecision::Refused);
        $visit->setClientDecisionAt(new \DateTimeImmutable('-2 days'))
            ->setRefusalOrigin(\App\Visit\Domain\RefusalOrigin::Client)
            ->setDecisionReminderSentAt(new \DateTimeImmutable('-1 day'))
            ->setReport('Bel appartement, mais trop sombre.')
            ->setClientFeeling(\App\Visit\Domain\ClientFeeling::Cold);
        $this->em->flush();

        $this->client->request('POST', $this->visitUrl($visit).'/statut', [
            '_token' => $this->statusToken($visit),
            'status' => 'cancelled',
        ]);
        self::assertResponseStatusCodeSame(303);

        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame(VisitStatus::Cancelled, $reloaded->getStatus());
        // Les champs du compte-rendu client n'ont plus de sens : purgés.
        self::assertNull($reloaded->getClientDecision());
        self::assertNull($reloaded->getClientDecisionAt());
        self::assertNull($reloaded->getRefusalOrigin());
        self::assertNull($reloaded->getApplicationOutcome());
        self::assertNull($reloaded->getDecisionDeadline());
        self::assertNull($reloaded->getDecisionReminderSentAt());
        // Le vécu de l'agent survit (choix assumé).
        self::assertSame('Bel appartement, mais trop sombre.', $reloaded->getReport());
        self::assertSame(\App\Visit\Domain\ClientFeeling::Cold, $reloaded->getClientFeeling());
    }

    public function testTheRefusedCounterOnlyCountsDoneVisits(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done, ClientDecision::Refused);
        $visit->setRefusalOrigin(\App\Visit\Domain\RefusalOrigin::Client);
        $this->em->flush();
        $repository = $this->em->getRepository(Visit::class);
        $dossierId = (int) $visit->getDossier()->getId();

        self::assertSame(1, $repository->countRefusedByDossier($dossierId));

        // Visite annulée après coup (reliquat de décision en base) : elle
        // n'a jamais eu lieu, le compteur redescend.
        $visit->setStatus(VisitStatus::Cancelled);
        $this->em->flush();
        self::assertSame(0, $repository->countRefusedByDossier($dossierId));
    }

    public function testDeadlineOverflowAndOutOfBoundsDatesAreRejected(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done, ClientDecision::Thinking);
        $token = $this->decisionToken($visit);

        // Débordement silencieux de createFromFormat : 31 février.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $token, 'deadline' => '2026-02-31',
        ]);
        self::assertResponseStatusCodeSame(400);

        // Passé : hors bornes.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $token, 'deadline' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
        ]);
        self::assertResponseStatusCodeSame(400);

        // Au-delà de deux ans : hors bornes.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $token, 'deadline' => (new \DateTimeImmutable('+3 years'))->format('Y-m-d'),
        ]);
        self::assertResponseStatusCodeSame(400);

        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getDecisionDeadline());

        // Le jour même reste valide (borne incluse).
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $token, 'deadline' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d'),
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertNotNull($this->em->find(Visit::class, $visit->getId())->getDecisionDeadline());
    }

    public function testAProlongedDeadlineRearmsTheStaffReminder(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done, ClientDecision::Thinking);
        // Premier cycle déjà rappelé : échéance dépassée, rappel parti.
        $visit->setDecisionDeadline(new \DateTimeImmutable('-3 days'))
            ->setDecisionReminderSentAt(new \DateTimeImmutable('-2 days'));
        $this->em->flush();

        // Prolongation accordée au client : nouvelle échéance (aujourd'hui).
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'deadline' => $today->format('Y-m-d'),
        ]);
        self::assertResponseStatusCodeSame(303);

        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame($today->format('Y-m-d'), $reloaded->getDecisionDeadline()?->format('Y-m-d'));
        // Le rappel est réarmé : un second rappel partira au prochain cron.
        self::assertNull($reloaded->getDecisionReminderSentAt());
        $sender = static::getContainer()->get(\App\Visit\Service\DecisionReminderSender::class);
        self::assertSame(1, $sender->send($today));
    }

    public function testChangingTheDecisionRearmsTheStaffReminder(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(VisitStatus::Done, ClientDecision::Thinking);
        $visit->setDecisionReminderSentAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        // La décision quitte "Réfléchit" : le rappel se réarme, un retour
        // ultérieur à la réflexion repart d'un cycle propre.
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', [
            '_token' => $this->decisionToken($visit),
            'decision' => 'positioning',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getDecisionReminderSentAt());
    }

    public function testDecisionRoutesFollowTheSecurityModel(): void
    {
        $visit = $this->persistVisit(VisitStatus::Done);

        // Anonyme sur un mauvais préfixe : 404 avant tout challenge.
        $this->client->request('POST', '/fr/00000000000000000000000000000000/admin/visites/'.$visit->getReference().'/retour-client', ['_token' => 'x', 'decision' => 'refused']);
        self::assertResponseStatusCodeSame(404);

        // Staff hors section : 403.
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']);
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', ['_token' => 'x', 'decision' => 'refused']);
        self::assertResponseStatusCodeSame(403);

        // CSRF invalide : 403 même avec la section.
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $this->client->request('POST', $this->visitUrl($visit).'/retour-client', ['_token' => 'not-a-valid-token', 'decision' => 'refused']);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getClientDecision());
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

    /** Scrapes the CSRF token of the decision chips on the show page. */
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

    private function persistVisit(VisitStatus $status = VisitStatus::Planned, ?ClientDecision $decision = null): Visit
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
            ->setScheduledAt(new \DateTimeImmutable('-1 day 10:30'))
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setStatus($status)
            ->setClientDecision($decision)
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
            ->setEmail(bin2hex(random_bytes(4)).'@visit-decision-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
