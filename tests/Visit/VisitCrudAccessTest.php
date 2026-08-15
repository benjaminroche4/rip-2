<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP paths of the visit edit/delete flows: the edit page prefills the
 * form, deletion is CSRF-protected and refused outside the visits section.
 */
final class VisitCrudAccessTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-crud-test.local')->execute();
    }

    public function testShowPageRendersTheVisitDetails(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $visit = $this->persistVisit();

        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('12 rue de la Roquette, 75011 Paris', $crawler->filter('[data-testid="visit-show-page"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="visit-show-dossier"]'));
        // Une seule action sur la fiche : bouton Supprimer direct, sans menu.
        self::assertCount(1, $crawler->filter('[data-testid="visit-show-delete-trigger"]'));
    }

    public function testTabsShowTheUpcomingAndArchivedCounts(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $this->persistVisit();

        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1', $crawler->filter('[data-testid="nav-tab-upcoming"]')->text());
        self::assertStringContainsString('0', $crawler->filter('[data-testid="nav-tab-archive"]')->text());

        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites/archives');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1', $crawler->filter('[data-testid="nav-tab-upcoming"]')->text());
    }

    public function testListRowsLinkToTheShowPage(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $visit = $this->persistVisit();

        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="visit-row-link"]')->first();
        self::assertStringContainsString('/admin/visites/'.$visit->getId(), (string) $link->attr('href'));
    }

    public function testShowPageEmbedsTheInlineEditor(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $visit = $this->persistVisit();

        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getId());

        self::assertResponseIsSuccessful();
        // La card détail est le composant éditable, cadenas en tête.
        self::assertCount(1, $crawler->filter('[data-testid="visit-details-card"]'));
        self::assertCount(1, $crawler->filter('[data-testid="visit-details-lock"]'));
        // L'ancienne page modifier n'existe plus.
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getId().'/modifier');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRemovesTheVisitWithAValidToken(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $visit = $this->persistVisit();

        // Grab the CSRF token from the list page's delete modal.
        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request(
            'POST',
            '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getId().'/supprimer',
            ['_token' => $token],
        );

        self::assertResponseStatusCodeSame(303);
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testDeleteRejectsAnInvalidToken(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getId().'/supprimer',
            ['_token' => 'not-a-valid-token'],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testAVisitsSectionStaffCanDeleteToo(): void
    {
        // Contract: deletion is open to anyone with the visits section,
        // staff included (unlike leads, where deleting is admin-only).
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request(
            'POST',
            '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getId().'/supprimer',
            ['_token' => $token],
        );

        self::assertResponseStatusCodeSame(303);
        self::assertSame(0, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testDeleteRequiresTheVisitsSection(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getId().'/supprimer',
            ['_token' => 'irrelevant'],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em->getRepository(Visit::class)->count([]));
    }

    public function testNewPagePreselectsTheDossierFromTheQueryReference(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $visit = $this->persistVisit();
        $dossier = $visit->getDossier();

        $crawler = $this->client->request(
            'GET',
            '/fr/'.$this->adminPrefix.'/admin/visites/nouvelle?dossier='.$dossier->getReference(),
        );

        self::assertResponseIsSuccessful();
        // Le select natif du formulaire porte l'option du dossier, sélectionnée.
        $selected = $crawler->filter('[data-testid="visit-form-dossier"] option[selected]');
        self::assertCount(1, $selected);
        self::assertSame((string) $dossier->getId(), $selected->attr('value'));

        // Référence inconnue : la page s'ouvre simplement sans présélection.
        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites/nouvelle?dossier=DS-999999');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="visit-form-dossier"] option[selected]'));
    }

    private function persistVisit(): Visit
    {
        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);

        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt(new \DateTimeImmutable('+1 day 10:30'))
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-crud-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
