<?php

namespace App\Tests\Admin;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
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

        // Weekly comparison table: 7 rows, 7 columns each (day + 3 contact + 3 call cells).
        $weeklyRows = $crawler->filter('[data-testid="weekly-comparison"] tbody tr');
        self::assertCount(7, $weeklyRows);
        self::assertCount(7, $weeklyRows->eq(0)->filter('td'));

        $robots = (string) $this->client->getResponse()->headers->get('X-Robots-Tag');
        self::assertStringContainsString('noindex', $robots);
        self::assertStringContainsString('nofollow', $robots);
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
        $user = static::getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        self::assertNotNull($user);
        $this->client->loginUser($user);
    }
}
