<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\DossierStatus;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Twig\Components\DossierList;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Dossier:DossierList behaviour: status filter chips with counts and the
 * free-text search, both over the effective status.
 */
final class DossierListTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@dossier-list-test.local')->execute();
    }

    public function testCountsAndFilterFollowTheEffectiveStatus(): void
    {
        $this->persistDossier('Famille Martin', 'Jean', 'Martin', status: DossierStatus::Searching);
        $closed = $this->persistDossier('Famille Durand', 'Paul', 'Durand', status: DossierStatus::Searching);
        $closed->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->loginAsAdmin();

        $component = $this->mountList();

        // Le dossier clôturé a quitté la liste courante : il ne compte plus
        // ni dans les chips de statut, ni dans le total.
        $counts = $component->getStatusCounts();
        self::assertSame(1, $counts['searching']);
        self::assertSame(0, $counts['closed']);
        self::assertSame(1, $component->getTotalCount());
        self::assertSame(['Famille Martin'], array_column($component->getDossiers(), 'name'));

        // "Clôturé" n'est plus proposé comme filtre de statut.
        self::assertNotContains(DossierStatus::Closed, $component->getStatuses());
    }

    public function testClosedDossiersMoveToTheArchiveTab(): void
    {
        $this->persistDossier('Famille Martin', 'Jean', 'Martin', status: DossierStatus::Searching);
        $closed = $this->persistDossier('Famille Durand', 'Paul', 'Durand', status: DossierStatus::Searching);
        $closed->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->loginAsAdmin();

        $component = $this->mountList();
        self::assertSame(1, $component->getArchivedCount());

        $component->changeScope('archived');
        self::assertSame(['Famille Durand'], array_column($component->getDossiers(), 'name'));

        // Rouvert, il retrouve la liste courante et vide les archives.
        $closed->setClosedAt(null);
        $this->em->flush();

        $component = $this->mountList();
        self::assertSame(0, $component->getArchivedCount());
        self::assertSame(2, $component->getTotalCount());
    }

    public function testAnArchivedDossierUsesTheCompletedCard(): void
    {
        $closed = $this->persistDossier('Famille Durand', 'Paul', 'Durand');
        $closed->setClosedAt(new \DateTimeImmutable('2026-08-05'));
        $this->em->flush();
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('Dossier:DossierList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
            'scope' => 'archived',
        ]);

        // Card "terminé" : pastille verte, date de clôture, bilan chiffré.
        self::assertStringContainsString('data-testid="dossier-archived-badge"', $rendered);
        self::assertStringContainsString('Clôturé', $rendered);
        // Date affichée sans dépendre de la locale du run.
        self::assertMatchesRegularExpression('/05 \S+ 2026/u', $rendered);
        self::assertStringContainsString('data-testid="dossier-card-stats"', $rendered);
        // La durée évite la soustraction mentale entre les deux dates.
        self::assertStringContainsString('data-testid="dossier-archived-duration"', $rendered);
        // Visites à venir n'a plus de sens ici : la tuile porte le responsable.
        self::assertStringContainsString('data-testid="dossier-card-manager"', $rendered);
        self::assertStringNotContainsString('data-testid="dossier-card-upcoming-visits"', $rendered);
        // Pas la card courante : ni barre d'avancement ni statut.
        self::assertStringNotContainsString('data-testid="dossier-status-badge"', $rendered);
    }

    public function testArchivesAreOrderedByClosureDateNewestFirst(): void
    {
        $old = $this->persistDossier('Famille Ancienne', 'Paul', 'Ancien');
        $old->setClosedAt(new \DateTimeImmutable('2026-06-01'));
        $recent = $this->persistDossier('Famille Récente', 'Léa', 'Récente');
        $recent->setClosedAt(new \DateTimeImmutable('2026-08-05'));
        $this->em->flush();
        $this->loginAsAdmin();

        $component = $this->mountList();
        $component->changeScope('archived');

        self::assertSame(
            ['Famille Récente', 'Famille Ancienne'],
            array_column($component->getDossiers(), 'name'),
        );
    }

    public function testTheArchiveTabIsOfferedWithItsCount(): void
    {
        $closed = $this->persistDossier('Famille Durand', 'Paul', 'Durand');
        $closed->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('Dossier:DossierList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="dossiers-scope-archived"', $rendered);
        self::assertStringNotContainsString('data-testid="dossiers-filter-closed"', $rendered);
        // Onglet "Tous" par défaut : le dossier clôturé n'y est pas.
        self::assertStringNotContainsString('Famille Durand', $rendered);
    }

    public function testSearchMatchesNameTenantAndReference(): void
    {
        $dossier = $this->persistDossier('Famille Martin', 'Jean', 'Martin');
        $this->persistDossier('Famille Durand', 'Paul', 'Durand');
        $this->loginAsAdmin();

        $component = $this->mountList();

        $component->search = 'martin';
        self::assertCount(1, $component->getDossiers());

        $component->search = (string) $dossier->getReference();
        self::assertCount(1, $component->getDossiers());

        $component->search = 'introuvable';
        self::assertCount(0, $component->getDossiers());
    }

    public function testMineScopeKeepsOnlyTheDossiersIManage(): void
    {
        $mine = $this->persistDossier('Famille Martin', 'Jean', 'Martin');
        $this->persistDossier('Famille Durand', 'Paul', 'Durand');
        $this->loginAsAdmin();

        // Assign the first dossier to the logged-in admin.
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@dossier-list-test.local']);
        $mine->setManager($admin);
        $this->em->flush();

        $component = $this->mountList();
        self::assertSame(1, $component->getMineCount());
        self::assertSame(2, $component->getTotalCount());

        $component->changeScope('mine');
        $rows = $component->getDossiers();
        self::assertCount(1, $rows);
        self::assertSame('Famille Martin', $rows[0]->name);
        self::assertSame(1, $component->getTotalCount(), 'Counts follow the scope.');
    }

    public function testUnknownFilterValueIsRejected(): void
    {
        $this->loginAsAdmin();
        $component = $this->mountList();

        $this->expectException(AccessDeniedException::class);
        $component->filter('nonsense');
    }

    public function testListRendersFiltersAndCards(): void
    {
        $this->persistDossier('Famille Martin', 'Jean', 'Martin');
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('Dossier:DossierList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="dossiers-filters"', $rendered);
        self::assertStringContainsString('data-testid="dossiers-search"', $rendered);
        self::assertStringContainsString('data-testid="dossier-row"', $rendered);
        self::assertStringContainsString('data-testid="dossier-status-badge"', $rendered);
        self::assertStringContainsString('Famille Martin', $rendered);
    }

    public function testCardProgressBarFollowsTheSearchMoveInDate(): void
    {
        // Started 10 days ago, move-in in 10 days: halfway, green tone.
        $dossier = $this->persistDossier('Famille Martin', 'Jean', 'Martin');
        $dossier->setCreatedAt(new \DateTimeImmutable('-10 days'));
        $dossier->setSearch((new \App\Dossier\Entity\DossierSearch())->setMoveInAt(new \DateTimeImmutable('+10 days')));
        $this->em->flush();
        $this->loginAsAdmin();

        $summary = $this->mountList()->getDossiers()[0];
        self::assertNotNull($summary->timeline);
        self::assertNotNull($summary->moveInAt);
        self::assertEqualsWithDelta(50, $summary->timeline->percent, 1);

        $rendered = (string) $this->renderTwigComponent('Dossier:DossierList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);
        self::assertStringContainsString('data-testid="dossier-card-bar-green"', $rendered);
    }

    public function testCardWithoutMoveInDateShowsAnEmptyBar(): void
    {
        $this->persistDossier('Famille Martin', 'Jean', 'Martin');
        $this->loginAsAdmin();

        $summary = $this->mountList()->getDossiers()[0];
        self::assertNull($summary->timeline);
        self::assertNull($summary->moveInAt);

        $rendered = (string) $this->renderTwigComponent('Dossier:DossierList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);
        self::assertStringNotContainsString('data-testid="dossier-card-bar-', $rendered);
    }

    public function testCardVisitCountersComeFromTheRealVisits(): void
    {
        $dossier = $this->persistDossier('Famille Martin', 'Jean', 'Martin');
        $other = $this->persistDossier('Famille Durand', 'Paul', 'Durand');
        $this->persistVisit($dossier, '+3 days');
        $this->persistVisit($dossier, '+10 days');
        $this->persistVisit($dossier, '-5 days', VisitStatus::Done);
        // Cancelled visits never happened: excluded from both counters.
        $this->persistVisit($dossier, '+4 days', VisitStatus::Cancelled);
        $this->persistVisit($other, '-2 days', VisitStatus::Done);
        $this->loginAsAdmin();

        $counts = $this->mountList()->getVisitCounts();

        self::assertSame(['upcoming' => 2, 'total' => 3], $counts[$dossier->getId()]);
        self::assertSame(['upcoming' => 0, 'total' => 1], $counts[$other->getId()]);

        $rendered = (string) $this->renderTwigComponent('Dossier:DossierList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);
        self::assertStringContainsString('data-testid="dossier-card-upcoming-visits"', $rendered);
        self::assertStringContainsString('>3</span>', $rendered, 'The total counter shows the real 3 visits, not the old mocked 9.');
    }

    public function testCardVisitCountersAreZeroWithoutAnyVisit(): void
    {
        $dossier = $this->persistDossier('Famille Martin', 'Jean', 'Martin');
        $this->loginAsAdmin();

        self::assertArrayNotHasKey($dossier->getId(), $this->mountList()->getVisitCounts());

        $rendered = (string) $this->renderTwigComponent('Dossier:DossierList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);
        self::assertStringContainsString('data-testid="dossier-card-total-visits"', $rendered);
    }

    private function persistVisit(
        Dossier $dossier,
        string $scheduledAt,
        VisitStatus $status = VisitStatus::Planned,
    ): void {
        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt(new \DateTimeImmutable($scheduledAt))
            ->setAddress('12 rue de Test, Paris')
            ->setStatus($status)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();
    }

    private function mountList(): DossierList
    {
        /** @var DossierList $component */
        $component = $this->mountTwigComponent('Dossier:DossierList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        return $component;
    }

    private function persistDossier(
        string $name,
        string $firstName,
        string $lastName,
        DossierStatus $status = DossierStatus::New,
    ): Dossier {
        $dossier = (new Dossier())
            ->setName($name)
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setStatus($status);
        $person = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setEmail(strtolower($firstName).'@dossier-list-test.local')
            ->setPrimaryContact(true);
        $dossier->addPerson($person);
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@dossier-list-test.local')
            ->setFirstName('Admin')
            ->setLastName('Staff')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $admin->setPassword('irrelevant');
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($admin, 'main', $admin->getRoles()),
        );
    }
}
