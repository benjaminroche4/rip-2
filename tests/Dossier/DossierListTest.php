<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\DossierStatus;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Twig\Components\DossierList;
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

        $counts = $component->getStatusCounts();
        self::assertSame(1, $counts['searching']);
        self::assertSame(1, $counts['closed']);
        self::assertSame(2, $component->getTotalCount());

        $component->filter('closed');
        $rows = $component->getDossiers();
        self::assertCount(1, $rows);
        self::assertSame('Famille Durand', $rows[0]->name);
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
