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
        $em->createQuery('DELETE FROM '.\App\Dossier\Entity\Dossier::class.' d WHERE d.reference IN (:refs)')
            ->setParameter('refs', ['DS-771101', 'DS-771102'])
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

    public function testTheListOpenedOnAFilteredUrlShowsThatFilterOnly(): void
    {
        // Retour arrière depuis une fiche : l'URL porte le filtre, la liste
        // doit repartir dessus (et pas sur les cards "À traiter" par défaut).
        $this->loginAs(self::ADMIN_EMAIL);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $inProgress = (new Contact())
            ->setReference('CT-424243')
            ->setFirstName('Marc')->setLastName('Traité')
            ->setEmail('contacts-test-inprogress@example.com')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Déjà pris en charge.')
            ->setStatus(\App\Contact\Domain\ContactStatus::InProgress)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setLang('fr');
        $em->persist($inProgress);
        $em->flush();

        $crawler = $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'?statusFilter=in_progress');

        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('[data-testid="contact-card"]');
        self::assertSame(1, $cards->count(), 'Only the in-progress lead belongs to that filter.');
        self::assertStringContainsString('Marc Traité', $cards->first()->text());
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

    public function testContactPdfReturns404ForAnonymousOnAWrongPrefix(): void
    {
        $this->client->request('GET', $this->contactsUrl('00000000000000000000000000000000').'/CT-424242/pdf');

        // Wrong prefix must 404 before any auth challenge (no login redirect).
        self::assertResponseStatusCodeSame(404);
    }

    public function testContactPdfNeedsTheContactsSection(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::USER_EMAIL])
            ?? throw new \RuntimeException('Test user not found.');
        $user->setRoles(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242/pdf');

        self::assertResponseStatusCodeSame(403);
    }

    public function testContactPdfDownloadsWithTheSectionRole(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::USER_EMAIL])
            ?? throw new \RuntimeException('Test user not found.');
        $user->setRoles(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']);
        $em->flush();
        $this->client->loginUser($user);

        // The suite must never reach DocRaptor: swap the backend behind the
        // PdfRenderer interface, exactly what it exists for.
        static::getContainer()->set(\App\Shared\Pdf\PdfRenderer::class, new class implements \App\Shared\Pdf\PdfRenderer {
            public function render(string $html, ?\App\Shared\Pdf\PdfOptions $options = null): string
            {
                return "%PDF-1.4 stubbed";
            }
        });

        $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    public function testLeadPageShowsNoLinkedDossiersBannerWithoutDossier(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="contact-linked-dossiers-banner"]');
    }

    public function testLeadPageBannersTheDossierConvertedFromIt(): void
    {
        $this->persistLinkedDossier('DS-771101', 'Relocation Dupont', \App\Dossier\Domain\DossierPersonRole::TENANT, sourceContactReference: 'CT-424242');

        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="contact-linked-dossiers-banner"]', 'Dossier créé depuis ce lead');

        $entry = $crawler->filter('[data-testid="contact-linked-dossier"]');
        self::assertSame(1, $entry->count());
        self::assertStringContainsString('DS-771101', $entry->text());
        self::assertStringContainsString('Relocation Dupont', $entry->text());
        // Admin has the dossiers section: the entry links to the file.
        $link = $entry->filter('a')->count() > 0 ? $entry->filter('a') : $crawler->filter('a[data-testid="contact-linked-dossier"]');
        self::assertGreaterThan(0, $link->count());
        self::assertStringContainsString('/dossiers/DS-771101', (string) $link->attr('href'));
    }

    public function testLeadPageListsEveryDossierCarryingTheLeadEmail(): void
    {
        // No dossier was converted from this lead: two files simply carry
        // the same email, one as tenant, one as a closed follow-up request.
        $this->persistLinkedDossier('DS-771101', 'Relocation Dupont', \App\Dossier\Domain\DossierPersonRole::TENANT);
        $closed = $this->persistLinkedDossier('DS-771102', 'Relocation Acme', \App\Dossier\Domain\DossierPersonRole::FOLLOW_UP);
        $closed->setClosedAt(new \DateTimeImmutable());
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="contact-linked-dossiers-banner"]', 'Cette adresse apparaît dans 2 dossiers');

        $entries = $crawler->filter('[data-testid="contact-linked-dossier"]');
        self::assertSame(2, $entries->count());
        $text = $crawler->filter('[data-testid="contact-linked-dossiers-banner"]')->text();
        self::assertStringContainsString('Locataire', $text);
        self::assertStringContainsString('Demande de suivi', $text);
        self::assertStringContainsString('Clôturé', $text);
    }

    public function testLinkedDossierEntriesAreNotLinksWithoutTheDossiersSection(): void
    {
        $this->persistLinkedDossier('DS-771101', 'Relocation Dupont', \App\Dossier\Domain\DossierPersonRole::TENANT, sourceContactReference: 'CT-424242');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::USER_EMAIL])
            ?? throw new \RuntimeException('Test user not found.');
        $user->setRoles(['ROLE_SECTION_CONTACTS']);
        $em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $this->contactsUrl($this->adminPrefix).'/CT-424242');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="contact-linked-dossiers-banner"]');
        self::assertSelectorNotExists('a[data-testid="contact-linked-dossier"]');
        $banner = $crawler->filter('[data-testid="contact-linked-dossiers-banner"]');
        self::assertStringContainsString('DS-771101', $banner->text());
        self::assertSame(0, $banner->filter('a')->count(), 'No link at all without the dossiers section.');
    }

    private function persistLinkedDossier(
        string $reference,
        string $name,
        \App\Dossier\Domain\DossierPersonRole $role,
        ?string $sourceContactReference = null,
    ): \App\Dossier\Entity\Dossier {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $dossier = (new \App\Dossier\Entity\Dossier())
            ->setName($name)
            ->setReference($reference)
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setSourceContactReference($sourceContactReference);
        $person = (new \App\Dossier\Entity\DossierPerson())
            ->setRole($role)
            ->setFirstName('Léa')
            ->setLastName('Dupont')
            // Different case from the contact email on purpose: the lookup
            // must stay case-insensitive end to end.
            ->setEmail('Contacts-Test-Lead@example.com')
            ->setPrimaryContact(\App\Dossier\Domain\DossierPersonRole::TENANT === $role);
        $dossier->addPerson($person);
        $em->persist($dossier);
        $em->flush();

        return $dossier;
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
