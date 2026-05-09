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
            ->setCreatedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $admin = (new User())
            ->setEmail(self::ADMIN_EMAIL)
            ->setFirstName('Test')
            ->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable());
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

        // Activity chart canvas mounted with 2 series (contacts + calls), 12 buckets each.
        $canvas = $crawler->filter('canvas[data-testid="activity-chart"]');
        self::assertCount(1, $canvas);
        self::assertSame('chart', $canvas->attr('data-controller'));
        $labels = json_decode((string) $canvas->attr('data-chart-labels-value'), true);
        $series = json_decode((string) $canvas->attr('data-chart-series-value'), true);
        self::assertCount(12, $labels);
        self::assertCount(2, $series);
        foreach ($series as $serie) {
            self::assertCount(12, $serie['data']);
            self::assertNotEmpty($serie['label']);
            self::assertNotEmpty($serie['color']);
            self::assertNotEmpty($serie['fillColor']);
        }

        // Week-over-week bar chart: 7 day labels, 2 series of 7 points (current vs previous week).
        $weekCanvas = $crawler->filter('canvas[data-testid="week-vs-week-chart"]');
        self::assertCount(1, $weekCanvas);
        self::assertSame('chart-bars', $weekCanvas->attr('data-controller'));
        $weekLabels = json_decode((string) $weekCanvas->attr('data-chart-bars-labels-value'), true);
        $weekSeries = json_decode((string) $weekCanvas->attr('data-chart-bars-series-value'), true);
        self::assertCount(7, $weekLabels);
        self::assertCount(2, $weekSeries);
        foreach ($weekSeries as $serie) {
            self::assertCount(7, $serie['data']);
            self::assertNotEmpty($serie['label']);
            self::assertNotEmpty($serie['color']);
            self::assertNotEmpty($serie['fillColor']);
        }

        // KPI grid: 4 cards (calls 7d, contacts 7d, leads month, leads 12m).
        self::assertCount(4, $crawler->filter('[data-testid="kpi-grid"] > article'));

        // No contacts in DB (cleared in setUp) → all-time chart section is hidden.
        self::assertCount(0, $crawler->filter('canvas[data-testid="contacts-all-time-chart"]'));

        // No STRIPE_SECRET_KEY in .env.test → repo returns []  →  payments
        // chart section is hidden. Confirms the graceful-degradation path.
        self::assertCount(0, $crawler->filter('canvas[data-testid="payments-chart"]'));

        $robots = (string) $this->client->getResponse()->headers->get('X-Robots-Tag');
        self::assertStringContainsString('noindex', $robots);
        self::assertStringContainsString('nofollow', $robots);
    }

    public function testAdminSeesContactsAllTimeChartWhenContactsExist(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $today = new \DateTimeImmutable('today 12:00:00');
        $threeDaysAgo = $today->modify('-3 days');

        foreach ([$threeDaysAgo, $threeDaysAgo, $today] as $i => $createdAt) {
            $em->persist((new Contact())
                ->setFirstName('Jane')
                ->setLastName('Doe')
                ->setEmail('jane'.$i.'@example.com')
                ->setPhoneNumber('+33600000000')
                ->setHelpType('contact.contactForm.helpType.choice.1')
                ->setMessage('Hello')
                ->setLang('fr')
                ->setIp('127.0.0.1')
                ->setCreatedAt($createdAt));
        }
        $em->flush();

        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->adminUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();

        $canvas = $crawler->filter('canvas[data-testid="contacts-all-time-chart"]');
        self::assertCount(1, $canvas);
        self::assertSame('chart', $canvas->attr('data-controller'));

        $labels = json_decode((string) $canvas->attr('data-chart-labels-value'), true);
        $series = json_decode((string) $canvas->attr('data-chart-series-value'), true);

        // 4 contiguous days from first contact (today-3) to today, inclusive.
        self::assertCount(4, $labels);
        self::assertCount(1, $series);
        self::assertSame([2, 0, 0, 1], $series[0]['data']);
    }

    public function testAdminSeesUsersPage(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->adminUrl($this->adminPrefix).'/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Utilisateurs');
        self::assertCount(1, $crawler->filter('[data-testid="users-page"]'));

        // Sidebar exposes the dashboard + users links under the piloting section.
        self::assertSelectorExists('aside a[href$="/admin"]');
        self::assertSelectorExists('aside a[href$="/admin/users"]');

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
        $this->client->request('GET', $this->adminUrl('00000000000000000000000000000000').'/users');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSeesUserProfileByUniqueId(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $target = $this->findUser(self::USER_EMAIL);

        $url = $this->adminUrl($this->adminPrefix).'/users/'.$target->getUniqueId().'/test-user';
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Test User');
        self::assertCount(1, $crawler->filter('[data-testid="user-profile"]'));
        self::assertStringContainsString(self::USER_EMAIL, $crawler->filter('[data-testid="user-profile"]')->html());

        // Back link points at the list.
        self::assertSelectorExists('[data-testid="user-profile"] a[href$="/admin/users"]');
    }

    public function testUserProfileRedirectsWhenSlugIsStale(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $target = $this->findUser(self::USER_EMAIL);

        $url = $this->adminUrl($this->adminPrefix).'/users/'.$target->getUniqueId().'/old-slug';
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringEndsWith('/test-user', $location);
    }

    public function testUserProfileReturns404OnUnknownUlid(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        // Valid ULID format that doesn't match any persisted user.
        $url = $this->adminUrl($this->adminPrefix).'/users/01HZZZZZZZZZZZZZZZZZZZZZZZ/anything';
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUserProfileReturns404WithMalformedUlid(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        // Wrong shape (too short, includes excluded chars) → router doesn't match.
        $url = $this->adminUrl($this->adminPrefix).'/users/not-a-ulid/whatever';
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminCannotSeeUserProfile(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $target = $this->findUser(self::USER_EMAIL);

        $url = $this->adminUrl($this->adminPrefix).'/users/'.$target->getUniqueId().'/test-user';
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
