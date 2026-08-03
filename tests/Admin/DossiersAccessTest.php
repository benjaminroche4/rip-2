<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierNote;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Entity\DossierSearch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Locks down the /admin/dossiers route invariants:
 *   1. anonymous → login redirect on the real path
 *   2. logged-in non-admin → 403
 *   3. admin → 200 with the dossiers page (empty state + create button)
 *   4. wrong prefix → 404 even when authenticated
 *   5. existing dossiers are rendered as rows linking to their detail page
 *   6. detail page invariants (facts, persons, 404 on unknown reference)
 */
final class DossiersAccessTest extends WebTestCase
{
    private const USER_EMAIL = 'dossiers-test-user@example.com';
    private const ADMIN_EMAIL = 'dossiers-test-admin@example.com';
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

        $em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email IN (:emails)')
            ->setParameter('emails', [self::USER_EMAIL, self::ADMIN_EMAIL])
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

        $em->persist($user);
        $em->persist($admin);
        $em->flush();
    }

    private function dossiersUrl(string $prefix): string
    {
        return '/fr/'.$prefix.'/admin/dossiers';
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', $this->dossiersUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('connexion', $location);
    }

    public function testNonAdminGetsAccessDenied(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', $this->dossiersUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesBlankDossiersPage(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->dossiersUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dossiers-page"]');
        self::assertSelectorExists('[data-testid="dossiers-empty"]');
        self::assertSelectorExists('[data-testid="dossier-create-open"]');
        // Sidebar links to the page.
        self::assertSelectorExists('a[href$="/admin/dossiers"]');
    }

    public function testExistingDossiersAreRenderedAsRows(): void
    {
        $this->persistDossier();

        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->dossiersUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="dossiers-empty"]');
        self::assertCount(1, $crawler->filter('[data-testid="dossier-row"]'));
        self::assertStringContainsString('Famille Dupont', $crawler->filter('[data-testid="dossier-row"]')->text());
        self::assertStringContainsString('Jean Dupont', $crawler->filter('[data-testid="dossier-row"]')->text());
        // The row links to the detail page.
        $href = (string) $crawler->filter('[data-testid="dossier-row"] a')->attr('href');
        self::assertStringEndsWith('/admin/dossiers/DS-000042', $href);
    }

    public function testAdminSeesDossierDetailPage(): void
    {
        $this->persistDossier();

        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->dossiersUrl($this->adminPrefix).'/DS-000042');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dossier-show-page"]');
        self::assertSelectorExists('[data-testid="dossier-show-facts"]');
        self::assertSame('ABE78L', trim($crawler->filter('[data-testid="dossier-show-pairing-code"]')->text()));
        self::assertCount(2, $crawler->filter('[data-testid="dossier-show-person"]'));
        // The primary tag sits on the tenant card only.
        self::assertCount(1, $crawler->filter('[data-testid="dossier-show-primary-tag"]'));
        self::assertStringContainsString('Jean Dupont', $crawler->filter('[data-testid="dossier-show-persons"]')->text());
        self::assertStringContainsString('Marie Durand', $crawler->filter('[data-testid="dossier-show-persons"]')->text());
        // The 3 module placeholders (Dossier, Visite, Paiement).
        self::assertCount(3, $crawler->filter('[data-testid="dossier-module-card"]'));
        // The manager picker sits in the header, unassigned by default.
        self::assertSelectorExists('[data-testid="dossier-manager"]');
        // Search criteria + follow-up thread copied from the contact.
        self::assertStringContainsString('2500', $crawler->filter('[data-testid="dossier-show-search"]')->text());
        self::assertStringContainsString('Garant physique', $crawler->filter('[data-testid="dossier-show-search"]')->text());
        self::assertStringContainsString('01.10.2026', $crawler->filter('[data-testid="dossier-show-search"]')->text());
        // CSV multi-choice values are translated part by part, never shown as raw keys.
        self::assertStringContainsString('T2, T3', $crawler->filter('[data-testid="dossier-show-search"]')->text());
        self::assertStringContainsString('Non meublé, Meublé', $crawler->filter('[data-testid="dossier-show-search"]')->text());
        self::assertStringNotContainsString('furnishing.choice', $crawler->filter('[data-testid="dossier-show-search"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="dossier-note"]'));
        self::assertStringContainsString('Premier appel', $crawler->filter('[data-testid="dossier-show-followup"]')->text());
        // Origin entry with a clickable link back to the contact request.
        self::assertSelectorExists('[data-testid="dossier-origin"]');
        $originHref = (string) $crawler->filter('[data-testid="dossier-origin-link"]')->attr('href');
        self::assertStringEndsWith('/admin/contacts/CT-000123', $originHref);
    }

    public function testUnknownReferenceReturns404(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->dossiersUrl($this->adminPrefix).'/DS-999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousIsRedirectedToLoginOnDetail(): void
    {
        $this->persistDossier();
        $this->client->request('GET', $this->dossiersUrl($this->adminPrefix).'/DS-000042');

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('connexion', $location);
    }

    private function persistDossier(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('jean.dupont@example.com')
            ->setPhone('+33611223344')
            ->setPrimaryContact(true);
        $followUp = (new DossierPerson())
            ->setRole(DossierPersonRole::FOLLOW_UP)
            ->setFirstName('Marie')
            ->setLastName('Durand')
            ->setEmail('marie.durand@example.com');
        $dossier = (new Dossier())
            ->setName('Famille Dupont')
            ->setReference('DS-000042')
            ->setPairingCode('ABE78L')
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant)
            ->addPerson($followUp)
            ->setSearch((new DossierSearch())
                ->setBudget(2500)
                ->setAreas('11e, 12e')
                ->setMoveInAt(new \DateTimeImmutable('2026-10-01'))
                ->setPropertyType('t2,t3')
                ->setFurnishing('unfurnished,furnished')
                ->setGuarantorType('physical'))
            ->addNote((new DossierNote())
                ->setText('Premier appel, très motivée.')
                ->setAuthorId(1)
                ->setAuthorName('Alice Staff'))
            ->setSourceContactReference('CT-000123');
        $em->persist($dossier);
        $em->flush();
    }

    public function testWrongPrefixReturns404EvenAuthenticated(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->dossiersUrl('totally-wrong-prefix-123456'));

        self::assertResponseStatusCodeSame(404);
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
