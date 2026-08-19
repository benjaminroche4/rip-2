<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
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
 * Agency favorites of the agencies view, mirroring
 * the agent favorites: shared heart toggle, favorites tab filtering, and a
 * map with one marker per active geocoded agency.
 */
final class AgencyFavoritesTest extends KernelTestCase
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
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agency-favorites-test.local')->execute();
    }

    public function testAgencyFavoriteToggleIsPersistedAndTogglesOff(): void
    {
        $agency = $this->persistAgency('Foncia');
        $agencyId = (int) $agency->getId();
        $this->loginAsAdmin();

        $component = $this->mountList();
        $component->toggleAgencyFavorite($agencyId, $this->em);

        // Global flag persisted in DB: the same favorites for the whole team.
        $this->em->clear();
        self::assertNotNull($this->em->find(Agency::class, $agencyId)->getFavoritedAt());

        // Toggle-off: the heart removes the agency from the favorites.
        $component->toggleAgencyFavorite($agencyId, $this->em);
        $this->em->clear();
        self::assertNull($this->em->find(Agency::class, $agencyId)->getFavoritedAt());
    }

    public function testFavoritesTabFiltersTheAgenciesView(): void
    {
        $fav = $this->persistAgency('Foncia');
        $this->persistAgency('Century 21');
        $this->loginAsAdmin();

        $component = $this->mountList();
        $component->chooseView('agencies');
        $component->toggleAgencyFavorite((int) $fav->getId(), $this->em);
        $component->chooseTab('favorites');

        self::assertTrue($component->isFavoritesTab());
        $agencies = $component->getAgencies();
        self::assertCount(1, $agencies);
        self::assertSame('Foncia', $agencies[0]->name);
        self::assertTrue($agencies[0]->favorite);
        self::assertSame(1, $component->getTotalCount());
    }

    public function testFavoriteTabCounterFollowsTheActiveView(): void
    {
        // One favorite agent, two favorite agencies: the tab badge must show
        // the count of the active view, not a merged total.
        $agent = (new RealEstateAgent())
            ->setFirstName('Jean')->setLastName('Martin')
            ->setFavoritedAt(new \DateTimeImmutable())
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);
        $this->persistAgency('Foncia', favorite: true);
        $this->persistAgency('Century 21', favorite: true);
        $this->em->flush();
        $this->loginAsAdmin();

        $component = $this->mountList();
        self::assertSame(1, $component->getFavoriteTotal());

        $component->chooseView('agencies');
        self::assertSame(2, $component->getFavoriteTotal());
    }

    public function testAgenciesEmptyFavoritesStateRenders(): void
    {
        $this->persistAgency('Foncia');
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
            'view' => 'agencies',
            'tab' => 'favorites',
        ]);

        self::assertStringContainsString('data-testid="agencies-empty"', $rendered);
        self::assertStringContainsString('Aucun favori pour le moment', $rendered);
    }

    public function testAgencyCardAndTabsRenderOnTheAgenciesView(): void
    {
        $this->persistAgency('Foncia', favorite: true);
        $this->loginAsAdmin();

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
            'view' => 'agencies',
        ]);

        // The Tous / Favoris tab shows above the agencies view too; each
        // card carries the heart. No list/map toggle anymore.
        self::assertStringContainsString('data-testid="agents-tab"', $rendered);
        self::assertStringContainsString('data-testid="agents-tab-favorites"', $rendered);
        self::assertStringNotContainsString('data-testid="agencies-display"', $rendered);
        self::assertStringContainsString('data-testid="agency-favorite-toggle"', $rendered);
        self::assertStringContainsString('aria-pressed="true"', $rendered);
    }

    private function mountList(): AgentList
    {
        /** @var AgentList $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgentList', [
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        return $component;
    }

    private function persistAgency(
        string $name,
        ?float $lat = null,
        ?float $lng = null,
        ?string $address = null,
        bool $favorite = false,
        bool $deactivated = false,
    ): Agency {
        $agency = (new Agency())
            ->setName($name)
            ->setAddress($address)
            ->setLatitude($lat)
            ->setLongitude($lng)
            ->setFavoritedAt($favorite ? new \DateTimeImmutable() : null)
            ->setDeactivatedAt($deactivated ? new \DateTimeImmutable() : null)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agency);
        $this->em->flush();

        return $agency;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@agency-favorites-test.local')
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
