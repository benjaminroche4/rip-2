<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Domain\AgencyPosition;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\Brand;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Twig\Components\AgentList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * RealEstateAgent:AgentList behaviour: alphabetical directory order and the
 * free-text search over name, agency, email and phone.
 */
final class AgentListTest extends KernelTestCase
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
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agent-list-test.local')->execute();
    }

    public function testAgentsAreListedAlphabeticallyIgnoringCaseAndAccents(): void
    {
        $this->persistAgent('Zoé', 'Ébrard');
        $this->persistAgent('Anna', 'durand');
        $this->persistAgent('Paul', 'Astier');
        $this->loginAsAdmin();

        $rows = $this->mountList()->getAgents();

        self::assertSame(['Astier', 'durand', 'Ébrard'], array_column($rows, 'lastName'));
    }

    public function testListIsPagedAndGrowsWithMore(): void
    {
        for ($i = 1; $i <= 28; ++$i) {
            $this->persistAgent('Agent'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Zeta'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $this->loginAsAdmin();

        $component = $this->mountList();

        // Première page : 25 lignes, 3 derrière le "Voir plus".
        self::assertCount(25, $component->getAgents());
        self::assertSame(3, $component->getHiddenCount());
        self::assertSame(28, $component->getTotalCount());

        $component->more();
        self::assertCount(28, $component->getAgents());
        self::assertSame(0, $component->getHiddenCount());

        // Nouvelle recherche = retour à la première page.
        $component->search = 'zeta';
        $component->onSearchUpdated();
        self::assertSame(25, $component->limit);
    }

    public function testSearchMatchesNameAgencyEmailAndPhone(): void
    {
        $this->persistAgent('Jean', 'Martin', agency: 'Foncia Paris 11', email: 'jean@foncia.fr', phone: '+33611223344');
        $this->persistAgent('Paul', 'Durand', agency: 'Century 21', email: 'paul@century.fr', phone: '+33655667788');
        $this->loginAsAdmin();

        $component = $this->mountList();

        $component->search = 'martin';
        self::assertCount(1, $component->getAgents());

        $component->search = 'foncia';
        self::assertCount(1, $component->getAgents());

        $component->search = 'paul@century.fr';
        self::assertCount(1, $component->getAgents());

        $component->search = '+336556';
        self::assertCount(1, $component->getAgents());

        $component->search = 'introuvable';
        self::assertCount(0, $component->getAgents());

        // The count line reflects the active search (0 matches here)...
        self::assertSame(0, $component->getTotalCount());
        // ...while the toggle chip keeps the whole-directory total.
        self::assertSame(2, $component->getAgentTotal());
    }

    public function testListRendersSearchAndRows(): void
    {
        $this->persistAgent(
            'Jean',
            'Martin',
            agency: 'Foncia Paris 11',
            email: 'jean@foncia.fr',
            phone: '+33611223344',
            specialties: [AgentSpecialty::Location, AgentSpecialty::Transaction],
            position: AgencyPosition::Manager,
        );
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="agents-search"', $rendered);
        self::assertStringContainsString('data-testid="agent-row"', $rendered);
        self::assertStringContainsString('data-testid="agent-email"', $rendered);
        self::assertStringContainsString('data-testid="agent-phone"', $rendered);
        self::assertStringContainsString('Jean Martin', $rendered);
        self::assertStringContainsString('Foncia Paris 11', $rendered);
        // Discreet badges: both specialties next to the name, the position
        // appended to the agency line.
        self::assertSame(2, substr_count($rendered, 'data-testid="agent-specialty"'));
        self::assertStringContainsString('Location', $rendered);
        self::assertStringContainsString('Transaction', $rendered);
        self::assertStringContainsString('data-testid="agent-position"', $rendered);
        self::assertStringContainsString('Gérant', $rendered);
    }

    public function testNoteShowsInItsOwnBandOnlyForNotedAgents(): void
    {
        $this->persistAgent('Jean', 'Martin', note: 'Réactif sur WhatsApp, préfère le matin.');
        $this->persistAgent('Paul', 'Durand');
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        // Only the noted agent gets the dedicated band, with the text visible
        // in the card; the card of the other agent is unchanged.
        self::assertSame(1, substr_count($rendered, 'data-testid="agent-note"'));
        self::assertStringContainsString('Réactif sur WhatsApp, préfère le matin.', $rendered);
    }

    public function testAgentsListRendersTheAllFavoritesTabAndLegacyFavoritesUrlFallsBack(): void
    {
        $this->persistAgent('Jean', 'Martin');
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        // Segmented Tous / Favoris control above the agents list, like the
        // dossier scope; favorites is no longer a sidebar view.
        self::assertStringContainsString('data-testid="agents-tab-all"', $rendered);
        self::assertStringContainsString('data-testid="agents-tab-favorites"', $rendered);
        self::assertStringNotContainsString('data-testid="agents-view-favorites"', $rendered);

        // Legacy ?view=favorites URLs land on the agents view, favorites tab.
        $component = $this->mountList();
        $component->view = 'favorites';
        $component->mount();
        self::assertSame('agents', $component->view);
        self::assertTrue($component->isFavoritesTab());
    }

    public function testFavoriteToggleIsSharedAndFiltersTheFavoritesView(): void
    {
        $fav = $this->persistAgent('Jean', 'Martin');
        $favId = (int) $fav->getId();
        $this->persistAgent('Paul', 'Durand');
        $this->loginAsAdmin();

        $component = $this->mountList();
        $component->toggleFavorite($favId, $this->em);

        // Global flag persisted in DB: the same favorites for the whole team.
        $this->em->clear();
        self::assertNotNull($this->em->find(RealEstateAgent::class, $favId)->getFavoritedAt());

        $component->chooseTab('favorites');
        $agents = $component->getAgents();
        self::assertCount(1, $agents);
        self::assertSame('Jean', $agents[0]->firstName);
        self::assertTrue($agents[0]->favorite);
        self::assertSame(1, $component->getFavoriteTotal());

        // Toggle-off: the heart removes the agent from the favorites.
        $component->toggleFavorite($favId, $this->em);
        $this->em->clear();
        self::assertNull($this->em->find(RealEstateAgent::class, $favId)->getFavoritedAt());
    }

    public function testEditedAgentShowsItsLastEditDateInsteadOfTheCreationDate(): void
    {
        $edited = $this->persistAgent('Jean', 'Martin');
        $edited->setUpdatedAt(new \DateTimeImmutable('2026-08-16 10:00:00'));
        $this->em->flush();
        $this->persistAgent('Paul', 'Durand');
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        // Only the edited profile switches to its last edit date; the
        // untouched one keeps showing its creation date.
        self::assertSame(1, substr_count($rendered, 'data-testid="agent-updated-at"'));
    }

    public function testEmptyStateRendersWithoutAgents(): void
    {
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        self::assertStringContainsString('data-testid="agents-empty"', $rendered);
    }

    public function testAgenciesViewListsAgenciesWithTheirAgentCount(): void
    {
        // Foncia has two agents, Century one, Émeraude none (standalone).
        $this->persistAgent('Jean', 'Martin', agency: 'Foncia');
        $this->persistAgent('Paul', 'Durand', agency: 'Foncia');
        $this->persistAgent('Lea', 'Bern', agency: 'Century 21');
        $this->em->persist((new Agency())->setName('Émeraude')->setCreatedAt(new \DateTimeImmutable()));
        $this->em->flush();
        $this->loginAsAdmin();

        $component = $this->mountList();
        $component->chooseView('agencies');

        self::assertTrue($component->isAgenciesView());
        $agencies = $component->getAgencies();
        self::assertSame(['Century 21', 'Émeraude', 'Foncia'], array_column($agencies, 'name'));
        $byName = [];
        foreach ($agencies as $a) {
            $byName[$a->name] = $a->agentCount;
        }
        self::assertSame(2, $byName['Foncia']);
        self::assertSame(1, $byName['Century 21']);
        self::assertSame(0, $byName['Émeraude'], 'A standalone agency shows zero agents.');

        // Totals cover both directories regardless of the active view.
        self::assertSame(3, $component->getAgentTotal());
        self::assertSame(3, $component->getAgencyTotal());
    }

    public function testSearchFiltersTheAgenciesView(): void
    {
        $this->persistAgent('Jean', 'Martin', agency: 'Foncia');
        $this->persistAgent('Lea', 'Bern', agency: 'Century 21');
        $this->loginAsAdmin();

        $component = $this->mountList();
        $component->chooseView('agencies');
        $component->search = 'foncia';

        self::assertCount(1, $component->getAgencies());
        self::assertSame(1, $component->getTotalCount());
    }

    public function testAgenciesViewSearchMatchesTheBrand(): void
    {
        $this->persistAgent('Jean', 'Martin', agency: 'Agence du Marais');
        $this->persistAgent('Lea', 'Bern', agency: 'Century 21');
        $this->attachBrand('Agence du Marais', 'Orpi');
        $this->loginAsAdmin();

        $component = $this->mountList();
        $component->chooseView('agencies');
        $component->search = 'orpi';

        $agencies = $component->getAgencies();
        self::assertCount(1, $agencies);
        self::assertSame('Agence du Marais', $agencies[0]->name);
        self::assertSame('Orpi', $agencies[0]->brand);
    }

    public function testAgenciesViewRendersRowsAndCounts(): void
    {
        $this->persistAgent('Jean', 'Martin', agency: 'Foncia');
        $this->attachBrand('Foncia', 'Foncia Groupe');
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
            'view' => 'agencies',
        ]);

        self::assertStringContainsString('data-testid="agents-view-toggle"', $rendered);
        self::assertStringContainsString('data-testid="agency-row"', $rendered);
        self::assertStringContainsString('data-testid="agency-agent-count"', $rendered);
        self::assertStringContainsString('Foncia', $rendered);
        // The brand shows as a discreet badge next to the agency name.
        self::assertStringContainsString('data-testid="agency-brand"', $rendered);
        self::assertStringContainsString('Foncia Groupe', $rendered);
    }

    public function testUnknownViewIsRejected(): void
    {
        $this->loginAsAdmin();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->mountList()->chooseView('archived');
    }

    public function testMountIsDeniedWithoutTheAgentsSectionRole(): void
    {
        $staff = (new User())
            ->setEmail('staff@agent-list-test.local')
            ->setFirstName('Staff')->setLastName('Only')
            ->setRoles(['ROLE_STAFF'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($staff);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($staff, 'main', $staff->getRoles()));

        $this->expectException(AccessDeniedException::class);
        $this->mountList();
    }

    private function mountList(): AgentList
    {
        /** @var AgentList $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        return $component;
    }

    private function attachBrand(string $agencyName, string $brandName): void
    {
        $brand = new Brand($brandName);
        $this->em->persist($brand);

        $agency = $this->em->getRepository(Agency::class)->findOneBy(['name' => $agencyName]);
        self::assertNotNull($agency);
        $agency->setBrand($brand);
        $this->em->flush();
    }

    /**
     * @param list<AgentSpecialty> $specialties
     */
    private function persistAgent(
        string $firstName,
        string $lastName,
        ?string $agency = null,
        ?string $email = null,
        ?string $phone = null,
        array $specialties = [],
        ?AgencyPosition $position = null,
        ?string $note = null,
    ): RealEstateAgent {
        $agencyEntity = null;
        if (null !== $agency) {
            $agencyEntity = $this->em->getRepository(Agency::class)->findOneBy(['name' => $agency])
                ?? (new Agency())->setName($agency)->setCreatedAt(new \DateTimeImmutable());
            $this->em->persist($agencyEntity);
        }

        $agent = (new RealEstateAgent())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setAgency($agencyEntity)
            ->setEmail($email)
            ->setPhone($phone)
            ->setSpecialties($specialties)
            ->setPosition($position)
            ->setNote($note)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@agent-list-test.local')
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
