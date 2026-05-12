<?php

namespace App\Tests\Admin;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Locks down the /admin/payments route invariants:
 *   1. anonymous → login redirect on the real path
 *   2. logged-in non-admin → 403
 *   3. admin → 200, page renders, sidebar exposes the link
 *   4. wrong prefix → 404 even when authenticated
 *
 * Stripe data isn't asserted here — the API key is unset in .env.test so
 * the repository returns degraded values; we just verify the page boots.
 */
final class PaymentsAccessTest extends WebTestCase
{
    private const USER_EMAIL = 'payments-test-user@example.com';
    private const ADMIN_EMAIL = 'payments-test-admin@example.com';
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
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email IN (:emails)')
            ->setParameter('emails', [self::USER_EMAIL, self::ADMIN_EMAIL])
            ->execute();

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

    private function paymentsUrl(string $prefix): string
    {
        return '/fr/'.$prefix.'/admin/paiements';
    }

    private function paymentsDataUrl(string $prefix): string
    {
        return '/fr/'.$prefix.'/admin/paiements/donnees';
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', $this->paymentsUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('connexion', $location);
    }

    public function testAnonymousIsRedirectedToLoginOnDataEndpoint(): void
    {
        $this->client->request('GET', $this->paymentsDataUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('connexion', $location);
    }

    public function testNonAdminGetsAccessDenied(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', $this->paymentsUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonAdminGetsAccessDeniedOnDataEndpoint(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', $this->paymentsDataUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesShellWithLazyFrameAndSpinner(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->paymentsUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Paiements');

        // Shell ships a lazy turbo-frame pointing at the data endpoint
        // + a default spinner child so the admin sees a loading state
        // immediately rather than an empty middle of the page.
        $frame = $crawler->filter('turbo-frame[data-testid="payments-body"]');
        self::assertCount(1, $frame);
        self::assertSame('lazy', $frame->attr('loading'));
        self::assertStringEndsWith('/admin/paiements/donnees', (string) $frame->attr('src'));
        self::assertCount(1, $crawler->filter('[data-testid="payments-loading"]'));

        // Heavy data is NOT in the shell — proves the deferral worked.
        self::assertCount(0, $crawler->filter('[data-testid="payments-kpi-grid"]'));
    }

    public function testAdminSeesPaymentsDataFragment(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->paymentsDataUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        // Fragment wraps the content in the matching turbo-frame so Turbo
        // can swap it in place.
        self::assertCount(1, $crawler->filter('turbo-frame#payments-body'));

        // 3 KPI cards: total all-time + this month vs last month + this week vs last week.
        self::assertCount(3, $crawler->filter('[data-testid="payments-kpi-grid"] > article'));

        // Table section is always present (even empty); the sub-block
        // toggles between "empty placeholder" and "rendered table".
        self::assertCount(1, $crawler->filter('[data-testid="payments-table-section"]'));

        // No STRIPE_SECRET_KEY in .env.test → repo returns degraded values
        // → table is empty + charts are hidden. Same graceful-degradation
        // path as the dashboard contactsAllTime / payments sections.
        self::assertCount(0, $crawler->filter('[data-testid="payments-table"]'));
        self::assertCount(0, $crawler->filter('canvas[data-testid="payments-monthly-chart"]'));
        self::assertCount(0, $crawler->filter('canvas[data-testid="payments-weekly-chart"]'));
        self::assertCount(0, $crawler->filter('canvas[data-testid="payments-all-time-chart"]'));
        self::assertCount(0, $crawler->filter('canvas[data-testid="payments-weekday-chart"]'));
    }

    public function testWrongPrefixReturns404EvenAuthenticated(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->paymentsUrl('00000000000000000000000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testWrongPrefixOnDataEndpointReturns404EvenAuthenticated(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->paymentsDataUrl('00000000000000000000000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testSidebarExposesPaymentsLink(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside a[href$="/admin/paiements"]');
    }

    private function loginAs(string $email): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email])
            ?? throw new \RuntimeException('Test user not found: '.$email);
        $this->client->loginUser($user);
    }
}
