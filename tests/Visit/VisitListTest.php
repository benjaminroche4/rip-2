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
