<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\HouseholdTypology;
use App\Admin\Domain\PersonRole;
use App\Admin\Domain\RequestLanguage;
use App\Admin\Entity\Document;
use App\Admin\Entity\DocumentRequest;
use App\Admin\Entity\PersonRequest;
use App\Auth\Entity\User;
use App\Tests\Admin\Factory\DocumentFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Locks down the /admin/outils/documents route invariants:
 *   1. anonymous → login redirect on the real path
 *   2. logged-in non-admin → 403
 *   3. admin → 200 with the DocumentList component + the add button
 *   4. wrong prefix → 404 even when authenticated
 *   5. existing documents are rendered as rows
 */
final class DocumentsToolsAccessTest extends WebTestCase
{
    use Factories;

    private const USER_EMAIL = 'documents-test-user@example.com';
    private const ADMIN_EMAIL = 'documents-test-admin@example.com';
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

        // Same isolation strategy as PaymentsAccessTest: scope the cleanup
        // to our test users + every Document/DocumentRequest, so the suite
        // doesn't depend on full DB resets between runs. PersonRequest rows
        // are cleaned up by the cascade on DocumentRequest::$persons.
        $em->createQuery('DELETE FROM '.DocumentRequest::class)->execute();
        $em->createQuery('DELETE FROM '.Document::class)->execute();
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

    private function documentsUrl(string $prefix): string
    {
        return '/fr/'.$prefix.'/admin/outils/documents';
    }

    private function catalogueUrl(string $prefix): string
    {
        return '/fr/'.$prefix.'/admin/outils/documents/catalogue';
    }

    private function requestUrl(string $prefix): string
    {
        return '/fr/'.$prefix.'/admin/outils/documents/demande';
    }

    public function testAnonymousIsRedirectedToLoginOnHub(): void
    {
        $this->client->request('GET', $this->documentsUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('connexion', $location);
    }

    public function testAnonymousIsRedirectedToLoginOnCatalogue(): void
    {
        $this->client->request('GET', $this->catalogueUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('connexion', $location);
    }

    public function testNonAdminGetsAccessDeniedOnHub(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', $this->documentsUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonAdminGetsAccessDeniedOnCatalogue(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', $this->catalogueUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesDocumentsHubWithBothCards(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->documentsUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="tools-documents-page"]');
        self::assertSelectorExists('[data-testid="tool-catalogue-card"]');
        self::assertSelectorExists('[data-testid="tool-catalogue-cta"]');
        self::assertSelectorExists('[data-testid="tool-request-card"]');
        self::assertSelectorExists('[data-testid="tool-request-cta"]');
        // The hub doesn't render the list itself — it links to the catalogue.
        self::assertSelectorNotExists('[data-testid="document-list"]');
        // CTAs point to the right sub-pages.
        $catalogueHref = $this->client->getCrawler()->filter('[data-testid="tool-catalogue-cta"]')->attr('href');
        self::assertStringEndsWith('/admin/outils/documents/catalogue', (string) $catalogueHref);
        $requestHref = $this->client->getCrawler()->filter('[data-testid="tool-request-cta"]')->attr('href');
        self::assertStringEndsWith('/admin/outils/documents/demande', (string) $requestHref);
        // Recent-requests section renders with an empty state when nothing was sent yet.
        self::assertSelectorExists('[data-testid="recent-requests"]');
        self::assertSelectorNotExists('[data-testid="recent-requests-table"]');
    }

    public function testAdminSeesRecentRequestsTableWithDownloadLinks(): void
    {
        $this->persistRequest(HouseholdTypology::TWO_TENANTS, RequestLanguage::FR, [['Dupont', 'Jean'], ['Martin', 'Marie']], new \DateTimeImmutable('-2 hour'));
        $request2 = $this->persistRequest(HouseholdTypology::ONE_TENANT_ONE_GUARANTOR, RequestLanguage::EN, [['Bernard', 'Paul']], new \DateTimeImmutable('-1 hour'));

        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->documentsUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="recent-requests-table"]');
        self::assertCount(2, $crawler->filter('[data-testid="recent-request-row"]'));
        // Last names should appear in the rows.
        self::assertSelectorTextContains('[data-testid="recent-requests-table"]', 'Dupont');
        self::assertSelectorTextContains('[data-testid="recent-requests-table"]', 'Bernard');
        // Each row exposes a download link pointing at the PDF route for its id.
        $downloads = $crawler->filter('[data-testid="recent-request-download"]');
        self::assertCount(2, $downloads);
        $firstHref = (string) $downloads->first()->attr('href');
        self::assertMatchesRegularExpression('#/admin/outils/documents/demande/\d+/pdf$#', $firstHref);
        // The most recent request is rendered first (DESC by createdAt).
        self::assertStringEndsWith('/admin/outils/documents/demande/'.$request2->getId().'/pdf', $firstHref);
    }

    /**
     * @param list<array{0:string,1:string}> $persons last name + first name pairs
     */
    private function persistRequest(HouseholdTypology $typology, RequestLanguage $language, array $persons, \DateTimeImmutable $createdAt): DocumentRequest
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $request = (new DocumentRequest())
            ->setTypology($typology)
            ->setLanguage($language)
            ->setDriveLink('https://drive.example.test/'.bin2hex(random_bytes(4)))
            ->setCreatedAt($createdAt);

        foreach ($persons as $i => [$lastName, $firstName]) {
            $person = (new PersonRequest())
                ->setRole(PersonRole::TENANT)
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setPosition($i);
            $request->addPerson($person);
        }

        $em->persist($request);
        $em->flush();

        return $request;
    }

    public function testAdminSeesCatalogueWithEmptyState(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->catalogueUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="tools-documents-catalogue-page"]');
        self::assertSelectorExists('[data-testid="document-list"]');
        self::assertSelectorExists('[data-testid="document-add-button"]');
        self::assertCount(1, $crawler->filter('[data-testid="document-list-empty"]'));
        self::assertCount(0, $crawler->filter('[data-testid="document-table"]'));
    }

    public function testAdminSeesExistingDocumentsInTheCatalogue(): void
    {
        DocumentFactory::createOne([
            'nameFr' => 'Bail commercial',
            'nameEn' => 'Commercial lease',
            'slug' => 'bail-commercial',
        ]);
        DocumentFactory::createOne([
            'nameFr' => 'État des lieux',
            'nameEn' => 'Inventory of fixtures',
            'slug' => 'etat-des-lieux',
        ]);

        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->catalogueUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="document-table"]');
        self::assertCount(2, $crawler->filter('[data-testid="document-row"]'));
        self::assertSelectorTextContains('[data-testid="document-table"]', 'Bail commercial');
        self::assertSelectorTextContains('[data-testid="document-table"]', 'État des lieux');
    }

    public function testAnonymousIsRedirectedToLoginOnRequest(): void
    {
        $this->client->request('GET', $this->requestUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('connexion', $location);
    }

    public function testNonAdminGetsAccessDeniedOnRequest(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', $this->requestUrl($this->adminPrefix));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesRequestForm(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $crawler = $this->client->request('GET', $this->requestUrl($this->adminPrefix));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="tools-documents-request-page"]');
        // Form renders with one person pre-filled + ability to add more.
        self::assertSelectorExists('[data-testid="document-request-form"]');
        self::assertCount(1, $crawler->filter('[data-testid="document-request-person"]'));
        self::assertSelectorExists('[data-testid="document-request-add-person"]');
        // Each major section is present.
        self::assertSelectorExists('[data-testid="document-request-typology"]');
        self::assertSelectorExists('[data-testid="document-request-drive"]');
        self::assertSelectorExists('[data-testid="document-request-language"]');
        self::assertSelectorExists('[data-testid="document-request-submit"]');
        // Back link in the page header points at the documents hub.
        self::assertSelectorExists('a[href$="/admin/outils/documents"]');
    }

    public function testPdfRouteRequiresAdminEvenWithValidId(): void
    {
        $this->loginAs(self::USER_EMAIL);
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/outils/documents/demande/1/pdf');

        self::assertResponseStatusCodeSame(403);
    }

    public function testWrongPrefixReturns404OnRequestEvenAuthenticated(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->requestUrl('00000000000000000000000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testWrongPrefixReturns404OnHubEvenAuthenticated(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->documentsUrl('00000000000000000000000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testWrongPrefixReturns404OnCatalogueEvenAuthenticated(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('GET', $this->catalogueUrl('00000000000000000000000000000000'));

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
