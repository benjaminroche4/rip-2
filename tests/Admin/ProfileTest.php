<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Auth\Domain\Language;
use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Mon profil" page: every staff member sees their own info, read-only
 * rights, and can change their password (current password verified, new one
 * held to the reset-flow strength bar).
 */
final class ProfileTest extends WebTestCase
{
    private const PASSWORD = 'current-password';

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
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@profile-test.local')->execute();
    }

    public function testStaffSeesTheirOwnProfileWithReadOnlyRights(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_VISITS']);

        $this->client->request('GET', $this->profileUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mon profil');
        self::assertSelectorTextContains('[data-testid="profile-page"]', 'staff@profile-test.local');
        // Rights are read-only chips: granted for the owned section...
        self::assertSelectorTextContains('[data-testid="profile-access-visits"]', 'Accès');
        // ...denied elsewhere, and no admin toggle switches anywhere.
        self::assertSelectorTextContains('[data-testid="profile-access-contacts"]', 'Sans accès');
        self::assertSelectorNotExists('[data-testid="user-access-visits"]');
        // The password card is there with its live strength gauge.
        self::assertSelectorExists('[data-testid="profile-password-form"]');
        self::assertSelectorExists('[data-controller="password-strength"]');
    }

    public function testActivityBlocksFollowTheSectionAccess(): void
    {
        // Visits-only staff: their own assigned leads and managed dossiers
        // stay hidden, the sections they cannot open must not leak names.
        $user = $this->loginWithRoles(['ROLE_SECTION_VISITS']);
        $this->client->request('GET', $this->profileUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="profile-activity"]');

        // An admin holds every section: both blocks are there.
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', $this->profileUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="profile-activity"]');
        self::assertSelectorTextContains('[data-testid="profile-activity"]', 'Leads assignés');
        self::assertSelectorTextContains('[data-testid="profile-activity"]', 'Dossiers gérés');
    }

    public function testOwnLanguageIsEditableFromTheProfile(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_VISITS']);
        $this->client->request('GET', $this->profileUrl());

        self::assertResponseIsSuccessful();
        // Read-only until the pencil is clicked, but editable: any staff
        // member owns the language of their account.
        self::assertSelectorExists('[data-testid="user-language"]');
        self::assertSelectorExists('[data-testid="user-language-edit"]');
    }

    public function testBackOfficeFollowsTheAccountLanguage(): void
    {
        $user = $this->loginWithRoles(['ROLE_SECTION_VISITS']);
        $user->setLanguage(Language::En);
        $this->em->flush();
        $this->client->loginUser($user);

        // Opening a French BO URL with an English account lands on the
        // English one (localized path included: /visites -> /visits).
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites');

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/en/', $location);
        self::assertStringEndsWith('/visits', $location);

        // And the public site keeps its URL-driven locale: an English
        // account still browses the French pages it asked for.
        $this->client->followRedirects(true);
        $this->client->request('GET', '/fr/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/en/', $this->client->getRequest()->getUri());
        $this->client->followRedirects(false);
    }

    public function testSidebarLinksToTheProfileAboveLogout(): void
    {
        $this->loginWithRoles(['ROLE_STAFF']);

        $crawler = $this->client->request('GET', $this->profileUrl());

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('[data-testid="sidebar-user-menu"]')->html();
        self::assertStringContainsString('data-testid="sidebar-profile"', $menu);
        // The profile entry sits above the logout entry.
        self::assertLessThan(
            strpos($menu, 'data-testid="sidebar-logout"'),
            strpos($menu, 'data-testid="sidebar-profile"'),
        );
    }

    public function testPasswordChangeHappyPath(): void
    {
        $user = $this->loginWithRoles(['ROLE_STAFF']);

        $crawler = $this->client->request('GET', $this->profileUrl());
        $form = $crawler->filter('[data-testid="profile-password-form"]')->form([
            'profile_password[currentPassword]' => self::PASSWORD,
            'profile_password[plainPassword][first]' => 'Nouveau-mdp-solide-2026!',
            'profile_password[plainPassword][second]' => 'Nouveau-mdp-solide-2026!',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorExists('[data-testid="profile-password-success"]');

        $this->assertStoredPasswordIs((int) $user->getId(), 'Nouveau-mdp-solide-2026!');
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $user = $this->loginWithRoles(['ROLE_STAFF']);

        $crawler = $this->client->request('GET', $this->profileUrl());
        $this->client->submit($crawler->filter('[data-testid="profile-password-form"]')->form([
            'profile_password[currentPassword]' => 'pas-le-bon',
            'profile_password[plainPassword][first]' => 'Nouveau-mdp-solide-2026!',
            'profile_password[plainPassword][second]' => 'Nouveau-mdp-solide-2026!',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-testid="profile-password"]', 'Le mot de passe actuel est incorrect');

        $this->assertStoredPasswordIs((int) $user->getId(), self::PASSWORD);
    }

    public function testWeakNewPasswordIsRejected(): void
    {
        $user = $this->loginWithRoles(['ROLE_STAFF']);

        $crawler = $this->client->request('GET', $this->profileUrl());
        $this->client->submit($crawler->filter('[data-testid="profile-password-form"]')->form([
            'profile_password[currentPassword]' => self::PASSWORD,
            'profile_password[plainPassword][first]' => 'abc123',
            'profile_password[plainPassword][second]' => 'abc123',
        ]));

        self::assertResponseStatusCodeSame(422);

        $this->assertStoredPasswordIs((int) $user->getId(), self::PASSWORD);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', $this->profileUrl());

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('connexion', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /** Re-reads the user from a fresh manager: the kernel rebooted between requests. */
    private function assertStoredPasswordIs(int $userId, string $plainPassword): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        $fresh = $em->find(User::class, $userId);
        self::assertNotNull($fresh);

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');
        self::assertTrue($hasher->isPasswordValid($fresh, $plainPassword));
    }

    private function profileUrl(): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/mon-profil';
    }

    public function testSidebarNudgesTowardsTwoFactorUntilItIsEnabled(): void
    {
        $user = $this->loginWithRoles(['ROLE_SECTION_VISITS']);

        // 2FA off: the sidebar card invites to turn it on, pointing at the
        // two-factor anchor of the profile page.
        $this->client->request('GET', $this->profileUrl());
        self::assertSelectorExists('[data-testid="sidebar-2fa-nudge"]');
        self::assertSelectorTextContains('[data-testid="sidebar-2fa-nudge"]', 'Sécurisez votre compte');

        $user->setPlainTotpSecret('JBSWY3DPEHPK3PXP');
        $this->em->flush();

        // Enabled: nothing left in the sidebar.
        $this->client->request('GET', $this->profileUrl());
        self::assertSelectorNotExists('[data-testid="sidebar-2fa-nudge"]');
    }

    private function loginWithRoles(array $roles): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $user = (new User())
            ->setEmail('staff@profile-test.local')
            ->setFirstName('Sam')
            ->setLastName('Staff')
            ->setRoles($roles)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $user;
    }
}
