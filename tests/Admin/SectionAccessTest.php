<?php

namespace App\Tests\Admin;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Locks down the per-section access contract: every ROLE_SECTION_* opens
 * exactly its own section plus the dashboard (via ROLE_STAFF), and nothing
 * else. ROLE_ADMIN reaching everything is covered by AdminAccessTest.
 */
final class SectionAccessTest extends WebTestCase
{
    private const PASSWORD = 'password';

    private KernelBrowser $client;
    private string $adminPrefix;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
    }

    /**
     * @param list<string> $roles
     */
    private function loginWithRoles(array $roles): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $user = (new User())
            ->setEmail('section-test@example.com')
            ->setFirstName('Test')
            ->setLastName('Staff')
            ->setRoles($roles)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function adminUrl(): string
    {
        return '/fr/'.$this->adminPrefix.'/admin';
    }

    public function testToolsOnlyUserReachesToolsAndDashboardButNothingElse(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_TOOLS']);

        $this->client->request('GET', $this->adminUrl().'/outils');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Outils');

        $this->client->request('GET', $this->adminUrl().'/outils/documents');
        self::assertResponseIsSuccessful();

        // The dashboard is open to every staff member.
        $this->client->request('GET', $this->adminUrl());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->adminUrl().'/contacts');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/dossiers');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/visites');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/utilisateurs');
        self::assertResponseStatusCodeSame(403);
    }

    public function testVisitsOnlyUserReachesVisitsAndDashboardButNothingElse(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_VISITS']);

        $this->client->request('GET', $this->adminUrl().'/visites');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Visites');

        // The new-visit page belongs to the same section, with the full
        // breadcrumb back to the visits list.
        $this->client->request('GET', $this->adminUrl().'/visites/nouvelle');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nouvelle visite');
        self::assertSelectorTextContains('[data-testid="admin-breadcrumb"]', 'Visites');

        // The dashboard is open to every staff member.
        $this->client->request('GET', $this->adminUrl());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->adminUrl().'/contacts');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/dossiers');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/outils');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/utilisateurs');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAgentsOnlyUserReachesAgentsAndDashboardButNothingElse(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        $this->client->request('GET', $this->adminUrl().'/agents-immobiliers');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Agents immobiliers');

        // The dashboard is open to every staff member.
        $this->client->request('GET', $this->adminUrl());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->adminUrl().'/contacts');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/dossiers');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/outils');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/utilisateurs');
        self::assertResponseStatusCodeSame(403);
    }

    public function testContactsOnlyUserReachesLeadsButNotToolsNorDossiers(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_CONTACTS']);

        $this->client->request('GET', $this->adminUrl().'/contacts');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->adminUrl().'/outils');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/dossiers');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDossiersOnlyUserReachesDossiersButNotLeads(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_DOSSIERS']);

        $this->client->request('GET', $this->adminUrl().'/dossiers');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->adminUrl().'/contacts');
        self::assertResponseStatusCodeSame(403);
    }

    public function testBareStaffOnlySeesTheDashboard(): void
    {
        $this->loginWithRoles(['ROLE_STAFF']);

        $this->client->request('GET', $this->adminUrl());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->adminUrl().'/contacts');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/dossiers');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/visites');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/agents-immobiliers');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/outils');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/utilisateurs');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSidebarShowsOnlyGrantedSections(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_TOOLS']);
        $this->client->request('GET', $this->adminUrl().'/outils');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside a[href$="/admin/outils"]');
        self::assertSelectorExists('aside a[href$="/admin"]');
        self::assertSelectorNotExists('aside a[href$="/admin/contacts"]');
        self::assertSelectorNotExists('aside a[href$="/admin/dossiers"]');
        self::assertSelectorNotExists('aside a[href$="/admin/visites"]');
        self::assertSelectorNotExists('aside a[href$="/admin/utilisateurs"]');
    }

    public function testPlainUserIsDeniedEverywhere(): void
    {
        $this->loginWithRoles([]);

        $this->client->request('GET', $this->adminUrl());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl().'/outils');
        self::assertResponseStatusCodeSame(403);
    }

    public function testWrongPrefixReturns404EvenForStaff(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_TOOLS']);
        $this->client->request('GET', '/fr/00000000000000000000000000000000/admin/outils');

        self::assertResponseStatusCodeSame(404);
    }
}
