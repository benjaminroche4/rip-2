<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Entity\Visit;
use App\Visit\Twig\Components\VisitArchive;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Visit:VisitArchive behaviour: every day before today lands in the
 * archive by itself (nothing manual), grouped by day most recent first,
 * with the "see more" pagination widening the window.
 */
final class VisitArchiveTest extends KernelTestCase
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
        self::getContainer()->set('clock', new MockClock(self::NOW, 'Europe/Paris'));

        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-archive-test.local')->execute();

        $this->dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($this->dossier);
        $this->em->flush();

        $this->loginAsAdmin();
    }

    public function testDaysBeforeTodayArchiveByThemselvesMostRecentFirst(): void
    {
        $this->persistVisit('2026-06-13 10:00', 'Avant-hier 10h');
        $this->persistVisit('2026-06-14 16:00', 'Hier 16h');
        $this->persistVisit('2026-06-14 09:00', 'Hier 9h');
        // Today and tomorrow stay OUT of the archive until midnight.
        $this->persistVisit('2026-06-15 08:00', 'Ce matin (déjà passée)');
        $this->persistVisit('2026-06-16 11:00', 'Demain');

        $component = $this->mountComponent();

        $groups = $component->getVisitsByDay();
        self::assertSame(['2026-06-14', '2026-06-13'], array_keys($groups), 'Most recent day first.');
        self::assertSame(['Hier 16h', 'Hier 9h'], array_column($groups['2026-06-14'], 'address'), 'Most recent visit first inside a day.');
        self::assertSame(3, $component->getTotalCount(), 'Today only greys out, it does not archive before midnight.');
    }

    public function testSeeMoreWidensTheWindowByPages(): void
    {
        // 30 archived visits: one page of 25, then 5 more.
        for ($i = 0; $i < 30; ++$i) {
            $this->persistVisit(sprintf('2026-06-%02d %02d:00', 5 + intdiv($i, 10), 8 + ($i % 10)), 'Visite '.$i);
        }

        $component = $this->mountComponent();

        self::assertSame(25, $component->getShownCount());
        self::assertTrue($component->hasMore());

        $component->loadMore();

        self::assertSame(30, $component->getShownCount());
        self::assertFalse($component->hasMore());
    }

    public function testRendersDayGroupsAndLoadMoreButton(): void
    {
        for ($i = 0; $i < 26; ++$i) {
            $this->persistVisit(sprintf('2026-06-10 %02d:%02d', 8 + intdiv($i, 4), 15 * ($i % 4)), 'Visite '.$i);
        }

        $rendered = (string) $this->renderTwigComponent('Visit:VisitArchive', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="visits-archive"', $rendered);
        self::assertStringContainsString('data-testid="visit-row"', $rendered);
        self::assertStringContainsString('data-testid="visits-archive-load-more"', $rendered);
    }

    public function testEmptyArchiveRendersItsEmptyState(): void
    {
        $rendered = (string) $this->renderTwigComponent('Visit:VisitArchive', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="visits-archive-empty"', $rendered);
    }

    public function testThePostStatusFilterNarrowsTheArchive(): void
    {
        // Trois issues différentes : le filtre n'en garde qu'une à la fois.
        $thinking = $this->persistVisit('2026-06-13 10:00', 'Réfléchit');
        $thinking->setStatus(\App\Visit\Domain\VisitStatus::Done)
            ->setReport('OK')
            ->setClientDecision(\App\Visit\Domain\ClientDecision::Thinking);
        $accepted = $this->persistVisit('2026-06-12 11:00', 'Validée');
        $accepted->setStatus(\App\Visit\Domain\VisitStatus::Done)
            ->setReport('OK')
            ->setApplicationOutcome(\App\Visit\Domain\ApplicationOutcome::Accepted);
        // Effectuée sans compte-rendu : attendue par le filtre report_due.
        $this->persistVisit('2026-06-11 09:00', 'Sans compte-rendu')
            ->setStatus(\App\Visit\Domain\VisitStatus::Done);
        $this->em->flush();

        $component = $this->mountComponent();
        self::assertSame(3, $component->getTotalCount());

        $component->choosePostStatus('thinking');
        self::assertSame(['Réfléchit'], $this->addresses($component));

        $component->choosePostStatus('accepted');
        self::assertSame(['Validée'], $this->addresses($component));

        $component->choosePostStatus('report_due');
        self::assertSame(['Sans compte-rendu'], $this->addresses($component));
        self::assertSame(1, $component->getTotalCount(), 'The counter follows the filter.');

        // Toggle-off : re-cliquer la chip active retire le filtre.
        $component->choosePostStatus('report_due');
        self::assertSame(3, $component->getTotalCount());
    }

    public function testAnUnknownPostStatusFromTheUrlFiltersNothing(): void
    {
        $this->persistVisit('2026-06-13 10:00', 'Hier');

        /** @var VisitArchive $component */
        $component = $this->mountTwigComponent('Visit:VisitArchive', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
            'postStatus' => 'DROP TABLE visit',
        ]);

        self::assertNull($component->getActivePostStatus());
        self::assertSame(1, $component->getTotalCount());
    }

    /** @return list<string> */
    private function addresses(VisitArchive $component): array
    {
        $addresses = [];
        foreach ($component->getVisitsByDay() as $visits) {
            foreach ($visits as $visit) {
                $addresses[] = $visit->address;
            }
        }

        return $addresses;
    }

    private function mountComponent(): VisitArchive
    {
        /** @var VisitArchive $component */
        $component = $this->mountTwigComponent('Visit:VisitArchive', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        return $component;
    }

    private function persistVisit(string $scheduledAt, string $address): Visit
    {
        $visit = (new Visit())
            ->setDossier($this->dossier)
            ->setReference('VS-'.random_int(100000, 999999))
            ->setScheduledAt(new \DateTimeImmutable($scheduledAt))
            ->setAddress($address)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@visit-archive-test.local')
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
