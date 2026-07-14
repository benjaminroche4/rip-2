<?php

namespace App\Tests\Admin;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Locks down the /admin/contacts route invariants:
 *   1. anonymous → login redirect on the real path
 *   2. logged-in non-admin → 403
 *   3. admin → 200, contact submissions rendered as cards, newest first
 *   4. wrong prefix → 404 even when authenticated
 *   5. sidebar exposes the link
 */
final class ContactsAccessTest extends WebTestCase
{
    private const USER_EMAIL = 'contacts-test-user@example.com';
    private const ADMIN_EMAIL = 'contacts-test-admin@example.com';
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
        $em->createQuery('DELETE FROM '.Contact::class.' c WHERE c.email = :email')
            ->setParameter('email', 'contacts-test-lead@example.com')
            ->execute();

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

        $contact = (new Contact())
            ->setFirstName('Léa')
            ->setLastName('Dupont')
            ->setEmail('contacts-test-lead@example.com')
            ->setPhoneNumber('+33612345678')
            ->setCompany('Acme Corp')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Bonjour, je cherche un appartement.')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setLang('fr');

        $em->persist($user);
        $em->persist($admin);
        $em->persist($contact);
        $em->flush();
    }

    private function contactsUrl(string $prefix): string
    {
        return '/fr/'.$prefix.'/admin/contacts';
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', $this->contactsUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('connexion', $location);
    }

    public function testNonAdminGetsAccessDenied(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', $this->contactsUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesContactCards(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->contactsUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Demandes de contact');

        $cards = $crawler->filter('[data-testid="contact-card"]');
        self::assertGreaterThanOrEqual(1, $cards->count());

        $cardText = $cards->first()->text();
        self::assertStringContainsString('Léa Dupont', $cardText);
        self::assertStringContainsString('contacts-test-lead@example.com', $cardText);
        self::assertStringContainsString('Bonjour, je cherche un appartement.', $cardText);
    }

    public function testWrongPrefixReturns404EvenAuthenticated(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->contactsUrl('00000000000000000000000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testSidebarExposesContactsLink(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside a[href$="/admin/contacts"]');
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
