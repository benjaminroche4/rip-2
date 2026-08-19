<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Entity\Visit;
use App\Visit\Twig\Components\VisitList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Visit:VisitList behaviour: today / tomorrow / later sections computed
 * against the (mocked) clock, and the day-map payload with pin numbers
 * matching the chronological position among ALL today's visits.
 */
final class VisitListTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const NOW = '2026-06-15 09:00:00';

    private EntityManagerInterface $em;
    private Dossier $dossier;

    protected function setUp(): void
    {
        self::bootKernel();

        // Le menu d'actions des rangées rend un token CSRF : il faut une
        // session sur la requête courante (absente en KernelTestCase).
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        // Pin "now" before anything instantiates the clock service.
        self::getContainer()->set('clock', new MockClock(self::NOW, 'Europe/Paris'));

        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-list-test.local')->execute();

        $this->dossier = $this->persistDossier('Famille Martin');
        $this->loginAsAdmin();
    }

    public function testVisitsAreSplitIntoTodayTomorrowAndLater(): void
    {
        $this->persistVisit('2026-06-14 15:00', 'Hier');       // past: excluded
        $this->persistVisit('2026-06-15 14:00', 'Auj 14h');
        $this->persistVisit('2026-06-15 10:00', 'Auj 10h');
        $this->persistVisit('2026-06-16 11:00', 'Demain 11h');
        $this->persistVisit('2026-06-20 09:30', 'Samedi 9h30');

        $component = $this->mountList();

        self::assertSame(['Auj 10h', 'Auj 14h'], array_column($component->getTodayVisits(), 'address'), 'Chronological within the day.');
        self::assertSame(['Demain 11h'], array_column($component->getTomorrowVisits(), 'address'));
        self::assertSame(['Samedi 9h30'], array_column($component->getLaterVisits(), 'address'));
        self::assertSame(4, $component->getTotalCount(), 'Past visits are excluded.');
    }

    public function testMapPayloadNumbersPinsLikeTheFullDayList(): void
    {
        // First visit of the day has no coordinates (failed geocoding): it
        // keeps position 1 in the list, so the geocoded ones are pins 2 and 3.
        $this->persistVisit('2026-06-15 09:30', 'Sans coordonnées');
        $this->persistVisit('2026-06-15 11:00', 'Bastille', lat: 48.8532, lng: 2.3692);
        $this->persistVisit('2026-06-15 15:00', 'République', lat: 48.8676, lng: 2.3633);

        $component = $this->mountList();

        $mappable = $component->getMappableTodayVisits();
        self::assertSame([2, 3], array_column($mappable, 'position'));

        $payload = json_decode($component->getMapPayload(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['2', '3'], array_column($payload, 'label'));
        self::assertSame([48.8532, 48.8676], array_column($payload, 'lat'));
        self::assertSame('11:00 Famille Martin', $payload[0]['title']);

        // Test env has no Google key: the walking route degrades to empty.
        self::assertSame('[]', $component->getRoutePayload());
    }

    public function testListRendersSectionsMapAndRows(): void
    {
        $this->persistVisit('2026-06-15 10:00', '12 rue de la Roquette', lat: 48.8553, lng: 2.3765);
        $this->persistVisit('2026-06-16 11:00', '5 rue Oberkampf');

        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="visits-today"', $rendered);
        self::assertStringContainsString('data-testid="visits-tomorrow"', $rendered);
        self::assertStringContainsString('data-testid="visits-map"', $rendered);
        self::assertStringContainsString('data-controller="visit-map"', $rendered);
        self::assertStringContainsString('data-testid="visit-row"', $rendered);
        self::assertStringContainsString('12 rue de la Roquette', $rendered);
        self::assertStringContainsString('Famille Martin', $rendered);
    }

    public function testTheComingDaysMapCarriesOneDatedDotPerVisit(): void
    {
        // Créée sans passer par Places : pas de coordonnées en base, mais une
        // adresse. Elle part quand même sur la carte, le controller la
        // géocode côté navigateur.
        $this->persistVisit('2026-06-20 09:30', 'Sans coordonnées');
        $this->persistVisit('2026-06-21 14:00', 'Nation', lat: 48.8484, lng: 2.3958);

        $component = $this->mountList();

        self::assertSame(
            ['Sans coordonnées', 'Nation'],
            array_column($component->getMappableLaterVisits(), 'address'),
        );

        $payload = json_decode($component->getLaterMapPayload(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $payload);
        self::assertNull($payload[0]['lat'], 'Sans coordonnées : le front géocode depuis l\'adresse.');
        self::assertSame('Sans coordonnées', $payload[0]['address']);
        self::assertSame(48.8484, $payload[1]['lat']);
        // Le survol du point donne la date : elle voyage dans le payload.
        self::assertStringContainsString('juin', $payload[1]['title']);
        self::assertStringContainsString('14h00', $payload[1]['title']);
    }

    public function testTheComingDaysSectionOffersItsMapBehindTheHeaderToggle(): void
    {
        $this->persistVisit('2026-06-20 09:30', 'Nation', lat: 48.8484, lng: 2.3958);

        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="visits-later-map-toggle"', $rendered);
        self::assertStringContainsString('data-controller="visit-days-map"', $rendered);
        // Repliée au chargement : la liste se lit d'abord.
        self::assertMatchesRegularExpression(
            '/class="[^"]*hidden[^"]*"[^>]*data-visit-days-map-target="frame"/',
            $rendered,
        );
    }

    public function testAVisitWithoutStoredCoordinatesStillGetsItsMap(): void
    {
        // Une adresse suffit : le controller géocode dans le navigateur, la
        // carte ne doit pas disparaître pour autant.
        $this->persistVisit('2026-06-20 09:30', '12 rue de la Roquette, 75011 Paris');

        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="visits-later"', $rendered);
        self::assertStringContainsString('data-testid="visits-later-map-toggle"', $rendered);
    }

    public function testRowShowsTheFirstPhotoAndNeverTheBooker(): void
    {
        $withPhoto = $this->persistVisit('2026-06-15 10:00', '12 rue de la Roquette');
        $photo = (new \App\Visit\Entity\VisitPhoto())
            ->setPath('visits/'.$withPhoto->getReference().'/photos/first.webp')
            ->setOriginalName('salon.webp')
            ->setMimeType('image/webp')
            ->setCreatedAt(new \DateTimeImmutable());
        $withPhoto->addPhoto($photo);
        $this->persistVisit('2026-06-16 11:00', '5 rue Oberkampf');
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertSame(1, substr_count($rendered, 'data-testid="visit-photo-thumb"'), 'Only the visit holding a photo shows the thumbnail.');
        self::assertStringContainsString(sprintf('/visites/%s/photos/%d', $withPhoto->getReference(), $photo->getId()), $rendered);
        self::assertStringNotContainsString('data-testid="visit-booked-by"', $rendered, 'The booker only shows on the detail page.');
    }

    public function testAPastPlannedVisitAsksForItsReport(): void
    {
        // 08h00 déjà passée (NOW = 09h00) et toujours "planned" : la card
        // réclame le compte-rendu; la visite à venir n'affiche rien.
        $this->persistVisit('2026-06-15 08:00', '12 rue de la Roquette');
        $this->persistVisit('2026-06-15 14:00', '5 rue Oberkampf');

        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="visit-report-due"', $rendered);
        self::assertStringContainsString('Compte-rendu à compléter', $rendered);
    }

    public function testADoneVisitWithoutItsReportStillAsksForIt(): void
    {
        // Marquée effectuée sans compte-rendu : le rappel reste; une fois le
        // compte-rendu rédigé, il disparaît.
        $done = $this->persistVisit('2026-06-15 08:00', '12 rue de la Roquette');
        $done->setStatus(\App\Visit\Domain\VisitStatus::Done);
        $complete = $this->persistVisit('2026-06-15 08:30', '5 rue Oberkampf');
        $complete->setStatus(\App\Visit\Domain\VisitStatus::Done)->setReport('RAS, client conquis.');
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertSame(1, substr_count($rendered, 'data-testid="visit-report-due"'));
    }

    public function testADoneVisitWithAFeelingShowsTheIconOnlyBadge(): void
    {
        // Effectuée avec ressenti : badge icône seule (libellé en title).
        $felt = $this->persistVisit('2026-06-15 08:00', '12 rue de la Roquette');
        $felt->setStatus(\App\Visit\Domain\VisitStatus::Done)
            ->setReport('OK')
            ->setClientFeeling(\App\Visit\Domain\ClientFeeling::Hot);
        // Effectuée sans ressenti : pas de badge.
        $blank = $this->persistVisit('2026-06-15 08:30', '5 rue Oberkampf');
        $blank->setStatus(\App\Visit\Domain\VisitStatus::Done)->setReport('OK');
        // Planifiée : jamais de badge, même avec un reliquat de ressenti.
        $this->persistVisit('2026-06-15 14:00', '3 rue Amelot');
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertSame(1, substr_count($rendered, 'data-testid="visit-feeling-badge"'));
        self::assertStringContainsString('title="Client chaud"', $rendered);
    }

    public function testAnUnassignedVisitWithTheClientPresentReadsByClient(): void
    {
        // Client présent sans assigné : il s'y rend seul, pas d'alerte.
        $alone = $this->persistVisit('2026-06-15 10:00', '12 rue de la Roquette');
        $alone->setClientPresent(true);
        $this->em->flush();
        // Ni assignée ni client présent : l'alerte ambre reste.
        $this->persistVisit('2026-06-16 11:00', '5 rue Oberkampf');

        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertSame(1, substr_count($rendered, 'data-testid="visit-by-client"'));
        self::assertSame(1, substr_count($rendered, 'data-testid="visit-unassigned"'));
        self::assertStringContainsString('Par le client', $rendered);
    }

    public function testImminentVisitsCarryADiscreetCountdownBadge(): void
    {
        // NOW = 09:00 : 09:30 -> "dans 30 min", 10:30 -> "dans 1 h",
        // 11:00 (120 min) -> "dans 2 h" ; 11:30 (> 2 h) et passée : rien.
        $this->persistVisit('2026-06-15 09:30', 'Dans trente minutes');
        $this->persistVisit('2026-06-15 10:30', 'Dans une heure');
        $this->persistVisit('2026-06-15 11:00', 'Dans deux heures');
        $this->persistVisit('2026-06-15 11:30', 'Plus tard');

        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertSame(3, substr_count($rendered, 'data-testid="visit-countdown"'));
        self::assertStringContainsString('dans 30 min', $rendered);
        self::assertStringContainsString('dans 1 h', $rendered);
        self::assertStringContainsString('dans 2 h', $rendered);
    }

    public function testAPositionedVisitWithoutOutcomeShowsTheWaitingBadge(): void
    {
        // Positionné il y a 3 jours, toujours sans réponse : badge d'attente.
        $waiting = $this->persistVisit('2026-06-15 08:00', 'En attente');
        $waiting->setStatus(\App\Visit\Domain\VisitStatus::Done)
            ->setReport('OK')
            ->setClientDecision(\App\Visit\Domain\ClientDecision::Positioning)
            // Ancré sur l'horloge réelle : le badge lit 'now' Twig, pas la
            // MockClock. -1h de marge pour un floor déterministe à 3 jours.
            ->setClientDecisionAt(new \DateTimeImmutable('-3 days -1 hour'));
        // Réponse arrivée : plus d'attente à afficher.
        $settled = $this->persistVisit('2026-06-15 09:30', 'Validée');
        $settled->setStatus(\App\Visit\Domain\VisitStatus::Done)
            ->setReport('OK')
            ->setClientDecision(\App\Visit\Domain\ClientDecision::Positioning)
            ->setApplicationOutcome(\App\Visit\Domain\ApplicationOutcome::Accepted);
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertSame(1, substr_count($rendered, 'data-testid="visit-waiting-chip"'));
        self::assertStringContainsString('En attente depuis 3 jours', $rendered);
    }

    public function testEmptyStateRendersWithoutVisits(): void
    {
        $rendered = (string) $this->renderTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="visits-empty"', $rendered);
    }

    private function mountList(): VisitList
    {
        /** @var VisitList $component */
        $component = $this->mountTwigComponent('Visit:VisitList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        return $component;
    }

    public function testTheUpcomingCounterIgnoresCancelledVisits(): void
    {
        $this->persistVisit('2026-06-15 14:00', 'Auj 14h');
        $this->persistVisit('2026-06-16 11:00', 'Demain 11h')
            ->setStatus(\App\Visit\Domain\VisitStatus::Cancelled);
        $this->em->flush();

        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Paris'));
        $repository = $this->em->getRepository(Visit::class);
        // Une visite annulée n'est pas "à venir" : elle sort du compteur
        // mais reste comptée dans l'archive une fois le jour passé.
        self::assertSame(1, $repository->countUpcoming($now));
    }

    private function persistVisit(string $scheduledAt, string $address, ?float $lat = null, ?float $lng = null): Visit
    {
        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($this->dossier)
            ->setScheduledAt(new \DateTimeImmutable($scheduledAt))
            ->setAddress($address)
            ->setLatitude($lat)
            ->setLongitude($lng)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    private function persistDossier(string $name): Dossier
    {
        $dossier = (new Dossier())
            ->setName($name)
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@visit-list-test.local')
            ->setFirstName('Admin')->setLastName('Staff')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($admin, 'main', $admin->getRoles()),
        );
    }
}
