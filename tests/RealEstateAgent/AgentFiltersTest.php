<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\Brand;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Twig\Components\AgentList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Discreet specialty + district filters of the agents directory: combinable
 * with the search and the All / Favorites tab, agents view only.
 */
final class AgentFiltersTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.Brand::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agent-filters-test.local')->execute();
    }

    public function testSpecialtyFilterKeepsRentalAgentsAndExcludesTransactionOnes(): void
    {
        $this->persistAgent('Lea', 'Loue', specialties: [AgentSpecialty::Location]);
        $this->persistAgent('Tom', 'Vend', specialties: [AgentSpecialty::Transaction]);
        $this->persistAgent('Ana', 'Mixte', specialties: [AgentSpecialty::Location, AgentSpecialty::Transaction]);
        $this->loginAsAdmin();

        $component = $this->mountList();
        $component->toggleSpecialty('location');

        $agents = $component->getAgents();
        self::assertSame(['Loue', 'Mixte'], array_column($agents, 'lastName'));
        self::assertSame(2, $component->getTotalCount());

        // Toggle-off: reclicking the active chip clears the filter.
        $component->toggleSpecialty('location');
        self::assertSame('', $component->specialty);
        self::assertCount(3, $component->getAgents());
    }

    public function testAreaFilterMatchesOwnCsvAndTheAgencyCsvWithoutPrefixFalsePositives(): void
    {
        // Independent agent covering the 11e and 12e by his own CSV.
        $this->persistAgent('Ines', 'Indep', areas: '11e,12e');
        // Agency agent inheriting the sector from his agency's CSV.
        $this->persistAgent('Aldo', 'Agence', agency: 'Foncia', agencyAreas: '12e,94');
        // Prefix trap: '1e' is not a match for '11e' (and vice versa).
        $this->persistAgent('Paul', 'Premier', areas: '1e');
        $this->loginAsAdmin();

        $component = $this->mountList();
        $component->chooseArea('12e');
        self::assertSame(['Agence', 'Indep'], array_column($component->getAgents(), 'lastName'));
        self::assertSame(2, $component->getTotalCount());

        $component->chooseArea('11e');
        self::assertSame(['Indep'], array_column($component->getAgents(), 'lastName'), "'1e' must not match the '11e' filter.");

        $component->chooseArea('94');
        self::assertSame(['Agence'], array_column($component->getAgents(), 'lastName'));

        // Reclicking the active entry (or picking "all") clears the filter.
        $component->chooseArea('94');
        self::assertSame('', $component->area);
        self::assertCount(3, $component->getAgents());
    }

    public function testFiltersCombineWithTheSearch(): void
    {
        $this->persistAgent('Lea', 'Martin', agency: 'Foncia', agencyAreas: '12e', specialties: [AgentSpecialty::Location]);
        $this->persistAgent('Tom', 'Martin', agency: 'Foncia', agencyAreas: '12e', specialties: [AgentSpecialty::Transaction]);
        $this->persistAgent('Ana', 'Martin', areas: '12e', specialties: [AgentSpecialty::Location]);
        $this->persistAgent('Zoe', 'Durand', areas: '12e', specialties: [AgentSpecialty::Location]);
        $this->loginAsAdmin();

        $component = $this->mountList();
        $component->search = 'martin';
        $component->toggleSpecialty('location');
        $component->chooseArea('12e');

        $agents = $component->getAgents();
        self::assertCount(2, $agents);
        self::assertSame(['Ana', 'Lea'], array_column($agents, 'firstName'));
        self::assertSame(2, $component->getTotalCount());
    }

    public function testUnknownUrlValuesAreNeutralizedAtRead(): void
    {
        $this->persistAgent('Lea', 'Loue', specialties: [AgentSpecialty::Location], areas: '11e');
        $this->loginAsAdmin();

        // Writable url props: a forged URL can put anything in them, the
        // component must fall back to '' (no filter) instead of querying.
        $component = $this->mountList();
        $component->specialty = 'achat"; DROP';
        $component->area = '99e';

        self::assertSame('', $component->getActiveSpecialty());
        self::assertSame('', $component->getActiveArea());
        self::assertCount(1, $component->getAgents());
        self::assertSame(1, $component->getTotalCount());
    }

    public function testUnknownValuesAreRejectedByTheActions(): void
    {
        $this->loginAsAdmin();
        $component = $this->mountList();

        try {
            $component->toggleSpecialty('achat');
            self::fail('An unknown specialty must be rejected.');
        } catch (NotFoundHttpException) {
        }

        $this->expectException(NotFoundHttpException::class);
        $component->chooseArea('21e');
    }

    public function testFilterChangeResetsThePagination(): void
    {
        $this->loginAsAdmin();
        $component = $this->mountList();
        $component->more();
        self::assertSame(50, $component->limit);

        $component->toggleSpecialty('location');
        self::assertSame(25, $component->limit);

        $component->more();
        $component->chooseArea('12e');
        self::assertSame(25, $component->limit);
    }

    public function testFilteredEmptyStateShowsWhenOnlyAFilterIsActive(): void
    {
        $this->persistAgent('Lea', 'Loue', specialties: [AgentSpecialty::Location]);
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
            'specialty' => 'transaction',
        ]);

        self::assertStringContainsString('data-testid="agents-empty"', $rendered);
        self::assertStringContainsString('Aucun agent ne correspond', $rendered);
    }

    public function testFilterControlsRenderOnTheAgentsViewOnly(): void
    {
        $this->persistAgent('Lea', 'Loue', agency: 'Foncia', specialties: [AgentSpecialty::Location]);
        $this->loginAsAdmin();

        $agentsView = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);
        self::assertStringContainsString('data-testid="agents-filter-location"', $agentsView);
        self::assertStringContainsString('data-testid="agents-filter-transaction"', $agentsView);
        self::assertStringContainsString('data-testid="agents-filter-area"', $agentsView);
        self::assertStringContainsString('data-testid="agents-filter-area-option-12e"', $agentsView);
        self::assertStringContainsString('data-controller="details-dropdown"', $agentsView);

        $agenciesView = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
            'view' => 'agencies',
        ]);
        self::assertStringNotContainsString('data-testid="agents-filter-location"', $agenciesView);
        self::assertStringNotContainsString('data-testid="agents-filter-area"', $agenciesView);
    }

    private function mountList(): AgentList
    {
        /** @var AgentList $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        return $component;
    }

    /**
     * @param list<AgentSpecialty> $specialties
     */
    private function persistAgent(
        string $firstName,
        string $lastName,
        ?string $agency = null,
        ?string $agencyAreas = null,
        array $specialties = [],
        ?string $areas = null,
    ): RealEstateAgent {
        $agencyEntity = null;
        if (null !== $agency) {
            $agencyEntity = $this->em->getRepository(Agency::class)->findOneBy(['name' => $agency])
                ?? (new Agency())->setName($agency)->setCreatedAt(new \DateTimeImmutable());
            $agencyEntity->setAreas($agencyAreas);
            $this->em->persist($agencyEntity);
        }

        $agent = (new RealEstateAgent())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setAgency($agencyEntity)
            ->setSpecialties($specialties)
            ->setAreas($areas)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@agent-filters-test.local')
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
