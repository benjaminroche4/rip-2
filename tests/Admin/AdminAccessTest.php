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
 * Locks down the admin space invariants:
 *   1. anonymous → login redirect on the real path
 *   2. logged-in non-admin → 403 on the real path
 *   3. admin → 200 + X-Robots-Tag noindex on the real path
 *   4. wrong prefix (router-format match) → 404, even authenticated as admin
 *   5. wrong prefix → does NOT trigger the security firewall (no login redirect)
 *      so the path format isn't revealed by probing
 */
final class AdminAccessTest extends WebTestCase
{
    private const USER_EMAIL = 'admin-test-user@example.com';
    private const ADMIN_EMAIL = 'admin-test-admin@example.com';
    private const PASSWORD = 'password';

    private KernelBrowser $client;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');

        $em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $em->createQuery('DELETE FROM '.User::class)->execute();
        $em->createQuery('DELETE FROM '.Contact::class)->execute();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get('security.user_password_hasher');

        $user = (new User())
            ->setEmail(self::USER_EMAIL)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $admin = (new User())
            ->setEmail(self::ADMIN_EMAIL)
            ->setFirstName('Test')
            ->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));

        $em->persist($user);
        $em->persist($admin);
        $em->flush();
    }

    private function adminUrl(string $prefix): string
    {
        return '/fr/'.$prefix.'/admin';
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', $this->adminUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('connexion', $location, 'Anonymous access must redirect to the login flow.');
    }

    public function testNonAdminGetsAccessDenied(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', $this->adminUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesDashboardWithNoIndexHeader(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->adminUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');

        // The analytics content (KPI tiles, charts, lazy frame) was removed
        // on purpose: the dashboard is a placeholder until rebuilt.
        self::assertSelectorExists('[data-testid="dashboard-page"]');
        self::assertSelectorTextContains('[data-testid="dashboard-page"]', 'En cours de construction');
        self::assertCount(0, $crawler->filter('turbo-frame[data-testid="dashboard-frame"]'));
        self::assertCount(0, $crawler->filter('[data-testid="kpi-grid"]'));
        self::assertCount(0, $crawler->filter('canvas'));

        $robots = (string) $this->client->getResponse()->headers->get('X-Robots-Tag');
        self::assertStringContainsString('noindex', $robots);
        self::assertStringContainsString('nofollow', $robots);
    }

    public function testAdminSeesUsersPage(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->adminUrl($this->adminPrefix).'/utilisateurs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Utilisateurs');
        self::assertCount(1, $crawler->filter('[data-testid="users-page"]'));

        // Sidebar exposes the dashboard + users links under the piloting section.
        self::assertSelectorExists('aside a[href$="/admin"]');
        self::assertSelectorExists('aside a[href$="/admin/utilisateurs"]');

        // Two users seeded in setUp() → table rendered with 2 rows, both emails visible.
        $rows = $crawler->filter('[data-testid="users-table"] tbody tr');
        self::assertCount(2, $rows);
        $body = $crawler->filter('[data-testid="users-table"]')->html();
        self::assertStringContainsString(self::USER_EMAIL, $body);
        self::assertStringContainsString(self::ADMIN_EMAIL, $body);

        // Admin row carries the admin role badge, the regular user the user one.
        self::assertStringContainsString('Admin', $body);
        self::assertStringContainsString('Utilisateur', $body);

        // Fresh users from setUp() never logged in → "Jamais" is the placeholder.
        self::assertStringContainsString('Jamais', $body);
    }

    public function testWrongPrefixOnUsersReturns404(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->adminUrl('00000000000000000000000000000000').'/utilisateurs');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSeesToolsPage(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->adminUrl($this->adminPrefix).'/outils');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Outils');
        self::assertCount(1, $crawler->filter('[data-testid="tools-page"]'));
        self::assertSelectorExists('aside a[href$="/admin/outils"]');
    }

    public function testNonAdminCannotSeeToolsPage(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', $this->adminUrl($this->adminPrefix).'/outils');

        self::assertResponseStatusCodeSame(403);
    }

    public function testWrongPrefixOnToolsReturns404(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->adminUrl('00000000000000000000000000000000').'/outils');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSeesToolsDocumentsPage(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->adminUrl($this->adminPrefix).'/outils/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Documents');
        self::assertCount(1, $crawler->filter('[data-testid="tools-documents-page"]'));

        // Hub exposes the catalogue card with a CTA pointing at the sub-page.
        self::assertSelectorExists('[data-testid="tool-catalogue-card"]');
        self::assertSelectorExists('[data-testid="tool-catalogue-cta"][href$="/admin/outils/documents/catalogue"]');

        // Back link in the page header points at the tools index.
        self::assertSelectorExists('a[href$="/admin/outils"]');
    }

    public function testNonAdminCannotSeeToolsDocumentsPage(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', $this->adminUrl($this->adminPrefix).'/outils/documents');

        self::assertResponseStatusCodeSame(403);
    }

    public function testWrongPrefixOnToolsDocumentsReturns404(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->adminUrl('00000000000000000000000000000000').'/outils/documents');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSeesUserProfileByUniqueId(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $target = $this->findUser(self::USER_EMAIL);

        $url = $this->adminUrl($this->adminPrefix).'/utilisateurs/'.$target->getUniqueId().'/test-user';
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Test User');
        self::assertCount(1, $crawler->filter('[data-testid="user-profile"]'));
        self::assertStringContainsString(self::USER_EMAIL, $crawler->filter('[data-testid="user-profile"]')->html());

        // Back link in the page header points at the list.
        self::assertSelectorExists('a[href$="/admin/utilisateurs"]');
    }

    public function testUserProfileRedirectsWhenSlugIsStale(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $target = $this->findUser(self::USER_EMAIL);

        $url = $this->adminUrl($this->adminPrefix).'/utilisateurs/'.$target->getUniqueId().'/old-slug';
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringEndsWith('/test-user', $location);
    }

    public function testUserProfileReturns404OnUnknownUlid(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        // Valid ULID format that doesn't match any persisted user.
        $url = $this->adminUrl($this->adminPrefix).'/utilisateurs/01HZZZZZZZZZZZZZZZZZZZZZZZ/anything';
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUserProfileReturns404WithMalformedUlid(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        // Wrong shape (too short, includes excluded chars) → router doesn't match.
        $url = $this->adminUrl($this->adminPrefix).'/utilisateurs/not-a-ulid/whatever';
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminCannotSeeUserProfile(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $target = $this->findUser(self::USER_EMAIL);

        $url = $this->adminUrl($this->adminPrefix).'/utilisateurs/'.$target->getUniqueId().'/test-user';
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function testWrongPrefixReturns404EvenForAdmin(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        // 32-char hex distinct from the configured prefix → router matches (regex passes)
        // but the controller's hash_equals check throws 404.
        $this->client->request('GET', $this->adminUrl('00000000000000000000000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testWrongPrefixDoesNotChallengeAnonymous(): void
    {
        // Critical anti-discovery check: an anonymous probe on a same-format URL
        // must NOT trigger a login redirect (which would reveal the format).
        $this->client->request('GET', $this->adminUrl('00000000000000000000000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    private function loginAs(string $email): void
    {
        $user = $this->findUser($email);
        $this->client->loginUser($user);
    }

    private function findUser(string $email): User
    {
        $user = static::getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        self::assertNotNull($user);

        return $user;
    }
}
