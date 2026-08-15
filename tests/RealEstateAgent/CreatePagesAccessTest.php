<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Access contract of the dedicated agent/agency creation pages, plus the
 * "Créer" menu now linking to them:
 *   1. wrong prefix → 404 before any auth challenge (even anonymous)
 *   2. anonymous on the real path → login redirect
 *   3. staff without ROLE_SECTION_AGENTS → 403
 *   4. staff with the section role → 200, form rendered, breadcrumb back
 */
final class CreatePagesAccessTest extends WebTestCase
{
    private const EMAIL_DOMAIN = '@agent-pages-test.local';

    private KernelBrowser $client;
    private string $adminPrefix;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')
            ->setParameter('p', '%'.self::EMAIL_DOMAIN)->execute();
    }

    /**
     * @param list<string> $roles
     */
    private function loginWithRoles(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).self::EMAIL_DOMAIN)
            ->setFirstName('Test')
            ->setLastName('Staff')
            ->setRoles($roles)
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function adminUrl(string $prefix): string
    {
        return '/fr/'.$prefix.'/admin';
    }

    public function testWrongPrefixReturns404BeforeAnyAuthChallenge(): void
    {
        foreach (['/agents-immobiliers/nouveau', '/agents-immobiliers/nouvelle-agence'] as $path) {
            $this->client->request('GET', $this->adminUrl('00000000000000000000000000000000').$path);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testWrongPrefixReturns404EvenForStaff(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        foreach (['/agents-immobiliers/nouveau', '/agents-immobiliers/nouvelle-agence'] as $path) {
            $this->client->request('GET', $this->adminUrl('00000000000000000000000000000000').$path);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testAnonymousIsRedirectedToLoginOnTheRealPath(): void
    {
        foreach (['/agents-immobiliers/nouveau', '/agents-immobiliers/nouvelle-agence'] as $path) {
            $this->client->request('GET', $this->adminUrl($this->adminPrefix).$path);
            self::assertResponseStatusCodeSame(302);
        }
    }

    public function testStaffWithoutTheAgentsSectionGets403(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_VISITS']);

        foreach (['/agents-immobiliers/nouveau', '/agents-immobiliers/nouvelle-agence'] as $path) {
            $this->client->request('GET', $this->adminUrl($this->adminPrefix).$path);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testAgentsSectionStaffSeesTheNewAgentPage(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        $this->client->request('GET', $this->adminUrl($this->adminPrefix).'/agents-immobiliers/nouveau');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nouvel agent immobilier');
        self::assertSelectorTextContains('[data-testid="admin-breadcrumb"]', 'Agents immobiliers');
        self::assertSelectorExists('[data-testid="agent-create-form"]');
        self::assertSelectorExists('[data-testid="agent-create-submit"]');
    }

    public function testAgentsSectionStaffSeesTheNewAgencyPage(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        $this->client->request('GET', $this->adminUrl($this->adminPrefix).'/agents-immobiliers/nouvelle-agence');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nouvelle agence');
        self::assertSelectorTextContains('[data-testid="admin-breadcrumb"]', 'Agents immobiliers');
        self::assertSelectorExists('[data-testid="agency-create-form"]');
        self::assertSelectorExists('[data-testid="agency-create-submit"]');
    }

    public function testEnglishSlugsServeTheSamePages(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        $this->client->request('GET', '/en/'.$this->adminPrefix.'/admin/real-estate-agents/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="agent-create-form"]');

        $this->client->request('GET', '/en/'.$this->adminPrefix.'/admin/real-estate-agents/new-agency');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="agency-create-form"]');
    }

    public function testCreateMenuLinksToTheDedicatedPages(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        $this->client->request('GET', $this->adminUrl($this->adminPrefix).'/agents-immobiliers');

        self::assertResponseIsSuccessful();
        // The menu items are plain links now: no embedded create component
        // (and therefore no modal) on the list page.
        self::assertSelectorExists('[data-testid="agent-create-open"][href$="/agents-immobiliers/nouveau"]');
        self::assertSelectorExists('[data-testid="agency-create-open"][href$="/agents-immobiliers/nouvelle-agence"]');
        self::assertSelectorNotExists('[data-testid="agent-create-form"]');
        self::assertSelectorNotExists('[data-testid="agency-create-form"]');
    }
}
