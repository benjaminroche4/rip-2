<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Badge "Visites" de la sidebar : compte les visites du jour courant en
 * heure murale Paris (horloge injectée), disparaît à zéro, et refuse de se
 * monter sans le rôle de la section.
 */
final class VisitsNavBadgeTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visits-navbadge-test.local')->execute();
    }

    public function testCountsOnlyTheVisitsOfTheCurrentDay(): void
    {
        self::getContainer()->set('clock', new MockClock('2030-04-02 10:00', 'Europe/Paris'));
        $this->persistVisit(new \DateTimeImmutable('2030-04-02 09:00'));
        $this->persistVisit(new \DateTimeImmutable('2030-04-02 18:30'));
        $this->persistVisit(new \DateTimeImmutable('2030-04-01 15:00'));
        $this->persistVisit(new \DateTimeImmutable('2030-04-03 09:00'));
        $this->loginAs(['ROLE_ADMIN']);

        $html = preg_replace('/\s+/', '', (string) $this->renderTwigComponent('Visit:VisitsNavBadge'));

        self::assertStringContainsString('>2<', $html);
    }

    public function testTheDayFollowsParisWallTimeNotTheClockTimezone(): void
    {
        // Non-régression (août 2026) : le badge lisait `new
        // DateTimeImmutable('today')` (fuseau serveur, horloge réelle) au
        // lieu de l'horloge injectée en heure Paris. À 23h30 UTC le 1er
        // avril, on est déjà le 2 à Paris : le badge doit compter le 2.
        self::getContainer()->set('clock', new MockClock('2030-04-01 23:30', 'UTC'));
        $this->persistVisit(new \DateTimeImmutable('2030-04-02 09:00'));
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);

        $html = preg_replace('/\s+/', '', (string) $this->renderTwigComponent('Visit:VisitsNavBadge'));

        self::assertStringContainsString('>1<', $html);
    }

    public function testTheBadgeHidesAtZero(): void
    {
        self::getContainer()->set('clock', new MockClock('2030-04-02 10:00', 'Europe/Paris'));
        $this->persistVisit(new \DateTimeImmutable('2030-04-01 15:00'));
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);

        $html = trim((string) $this->renderTwigComponent('Visit:VisitsNavBadge'));

        self::assertSame('', $html, 'No visit today, no badge at all.');
    }

    public function testStaffWithoutTheVisitsSectionCannotMountTheBadge(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']);

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Visit:VisitsNavBadge');
    }

    private function persistVisit(\DateTimeImmutable $scheduledAt): void
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
            ->setScheduledAt($scheduledAt)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visits-navbadge-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
