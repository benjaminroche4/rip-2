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
            ->setParameter('emails', [self::USER_EMAIL, self::ADMIN_EMAIL, 'contacts-test-operator@example.com'])
            ->execute();
        // Full purge: the list sorts untreated-oldest first, so a leftover
        // contact from another test would displace our fixture off card #1.
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

        $contact = (new Contact())
            ->setReference('CT-424242')
            ->setFirstName('Léa')
            ->setLastName('Dupont')
            ->setEmail('contacts-test-lead@example.com')
            ->setPhoneNumber('+33612345678')
            ->setCompany('Acme Corp')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Bonjour, je cherche un appartement.')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setLeadRating(4)
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
        self::assertSelectorTextContains('h1', 'Leads');

        $cards = $crawler->filter('[data-testid="contact-card"]');
        self::assertGreaterThanOrEqual(1, $cards->count());

        // The KPI band was removed on purpose: the page goes straight to
        // the list.
        self::assertSelectorNotExists('[data-testid="contacts-kpis"]');

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
        // The admin root forwards to the first accessible section.
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside a[href$="/admin/contacts"]');
    }

    public function testAdminSeesContactDetailPage(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Léa Dupont');
        // The lead-rating badge sits right next to the lead name, on the
        // heading row facing the Actions menu.
        self::assertSelectorTextContains('[data-testid="contact-detail"] [data-testid="lead-rating-title-badge"]', '4');
        self::assertSelectorExists('[data-testid="contact-detail"]');
        self::assertSelectorTextContains('[data-testid="contact-detail"]', 'CT-424242');
        self::assertSelectorTextContains('[data-testid="contact-detail"]', 'Acme Corp');
    }

    public function testDossierActionsNeedTheDossiersSection(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL])
            ?? throw new \RuntimeException('Test user not found.');

        // Contacts-only member: converting a lead needs the dossiers section
        // server-side, so the entry must not be offered here.
        $user->setRoles(['ROLE_SECTION_CONTACTS']);
        $em->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="contact-to-dossier-trigger"]');

        // With both sections, the action is back.
        $user->setRoles(['ROLE_SECTION_CONTACTS', 'ROLE_SECTION_DOSSIERS']);
        $em->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="contact-to-dossier-trigger"]');

        // Restore the admin role for the tests that follow.
        $user->setRoles(['ROLE_ADMIN']);
        $em->flush();
    }

    public function testUnknownReferenceReturns404(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-000001');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCardLinksToDetailPage(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->contactsUrl($this->adminPrefix));

        $link = $crawler->filter('[data-testid="contact-card-details"]')->first();
        self::assertGreaterThan(0, $link->count());
        self::assertStringContainsString('/admin/contacts/CT-424242', (string) $link->attr('href'));
    }

    public function testAdminDeletesAContactFromTheActionsMenu(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242');

        // L'entrée Supprimer est là pour un admin, avec sa confirmation.
        self::assertSelectorExists('[data-testid="contact-delete-trigger"]');

        $form = $crawler->filter('[data-testid="contact-delete-confirm"]')->closest('form')->form();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('/admin/contacts', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242');
        self::assertResponseStatusCodeSame(404);
    }

    public function testContactDeletionRequiresAdminAndCsrf(): void
    {
        // Un profil section Leads (non admin) : pas d'entrée ni de route.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $operator = (new User())
            ->setEmail('contacts-test-operator@example.com')
            ->setFirstName('Op')
            ->setLastName('Erator')
            ->setRoles(['ROLE_SECTION_CONTACTS'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $operator->setPassword($hasher->hashPassword($operator, self::PASSWORD));
        $em->persist($operator);
        $em->flush();

        $this->client->loginUser($operator);
        $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242');
        self::assertSelectorNotExists('[data-testid="contact-delete-trigger"]');

        $this->client->request('POST', $this->contactsUrl($this->adminPrefix).'/CT-424242/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        // Même admin, un token CSRF invalide est refusé.
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('POST', $this->contactsUrl($this->adminPrefix).'/CT-424242/supprimer', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
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
