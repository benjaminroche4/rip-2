<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\DossierStep;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Entity\DossierSearch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Rejoue le flux HTTP live réel (pattern ContactProjectLiveFlowTest) sur les
 * cards du détail dossier, pour les deux bugs de verrouillage et le menu des
 * équipements :
 *
 * - le cadenas reste ouvert après « Enregistrer » (la prop locked ne se
 *   referme jamais côté serveur ; le re-verrouillage venait du morph Turbo
 *   plein page, neutralisé par data-turbo-permanent sur les racines) ;
 * - la forme du DOM est identique verrouillé/déverrouillé (bandeau toujours
 *   rendu, masqué via hidden) : un bandeau rendu conditionnellement faisait
 *   bouger les frères du bloc de champs et le morph, qui préserve les
 *   sous-arbres contenant data-live-ignore, empilait l'ancien et le nouveau
 *   bloc (champs en double après déverrouillage) ;
 * - le menu « +N » des équipements reste ouvert après chaque sélection
 *   (état déplié en LiveProp, plus de dépliage purement DOM).
 */
final class DossierCardLockLiveFlowTest extends WebTestCase
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

        $this->em->createQuery('DELETE FROM '.\App\Visit\Entity\Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :e')
            ->setParameter('e', 'lock-flow-admin@example.com')->execute();

        $admin = (new User())
            ->setEmail('lock-flow-admin@example.com')
            ->setFirstName('Lock')->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin);
    }

    public function testPersonsCardStaysUnlockedAfterSaveAndKeepsAStableDomShape(): void
    {
        $dossier = $this->persistDossier();
        $crawler = $this->client->request('GET', $this->dossierUrl($dossier));
        self::assertResponseIsSuccessful();

        // La page ne doit jamais être restaurée depuis un snapshot Turbo
        // (cartes mortes, état périmé) et les racines des cards survivent au
        // morph plein page (le cadenas ne se referme qu'au prochain
        // chargement).
        self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');
        foreach (['dossier-show-persons', 'dossier-show-search', 'dossier-show-modules'] as $testId) {
            $root = $crawler->filter('[data-testid="'.$testId.'"][data-turbo-permanent]');
            self::assertCount(1, $root, $testId.' must be excluded from Turbo page morphs.');
            // data-turbo-permanent n'opère qu'avec un id (celui, déterministe,
            // posé par le système Live).
            self::assertNotEmpty((string) $root->attr('id'));
        }

        [$url, $csrf, $props] = $this->extractComponent($crawler, 'dossier-show-persons');

        // Verrouillé au chargement : bandeau visible (sans hidden).
        $banner = $crawler->filter('[data-testid="persons-locked-banner"]');
        self::assertCount(1, $banner);
        self::assertNull($banner->attr('hidden'));

        // Déverrouillage : le bandeau reste rendu (masqué), la forme du DOM
        // ne change pas, condition pour que le morph n'empile jamais les
        // champs en double.
        [$props, $html] = $this->action($url, $csrf, $props, 'toggleLock', [], 'dossier-show-persons');
        self::assertFalse($props['locked']);
        $unlocked = new Crawler($html);
        $banner = $unlocked->filter('[data-testid="persons-locked-banner"]');
        self::assertCount(1, $banner, 'The locked banner must stay in the DOM when unlocked (hidden), or the morph stacks the fields.');
        self::assertNotNull($banner->attr('hidden'));

        // Édition puis Enregistrer : la card reste déverrouillée et le
        // formulaire inline est rendu une seule fois.
        $personId = (int) $this->em->find(Dossier::class, $dossier->getId())->getPersons()->first()->getId();
        [$props, $html] = $this->action($url, $csrf, $props, 'startEdit', ['key' => $personId], 'dossier-show-persons');
        self::assertSame($personId, $props['editId']);
        self::assertSame(1, substr_count($html, 'id="pm-first"'), 'The edit fields must never appear twice.');

        [$props, $html] = $this->action($url, $csrf, $props, 'savePerson', [], 'dossier-show-persons');
        self::assertSame([], $props['errors'] ?? []);
        self::assertNull($props['editId'], 'A successful save closes the inline form.');
        self::assertFalse($props['locked'], 'Saving must NOT re-lock the card: the padlock only returns on the next page load.');
        self::assertSame(0, substr_count($html, 'id="pm-first"'));
    }

    public function testSearchCardBannerIsAlwaysRenderedAndEquipmentMenuStaysOpen(): void
    {
        $dossier = $this->persistDossier();
        $crawler = $this->client->request('GET', $this->dossierUrl($dossier));
        self::assertResponseIsSuccessful();

        [$url, $csrf, $props] = $this->extractComponent($crawler, 'dossier-show-search');

        // Déverrouillage : même invariant de forme que la card Personnes.
        [$props, $html] = $this->action($url, $csrf, $props, 'toggleLock', [], 'dossier-show-search');
        self::assertFalse($props['locked']);
        $unlocked = new Crawler($html);
        $banner = $unlocked->filter('[data-testid="search-locked-banner"]');
        self::assertCount(1, $banner);
        self::assertNotNull($banner->attr('hidden'));

        // Menu « +N » des équipements : replié au départ.
        $allAmenities = \count(\App\PropertyListing\Domain\Amenity::cases());
        self::assertLessThan($allAmenities, substr_count($html, 'data-testid="equipment-chip"'));
        self::assertStringContainsString('data-testid="equipment-show-more"', $html);

        // Dépliage : tous les chips sont là, le bouton « +N » disparaît.
        [$props, $html] = $this->action($url, $csrf, $props, 'revealEquipment', [], 'dossier-show-search');
        self::assertTrue($props['equipmentExpanded']);
        self::assertSame($allAmenities, substr_count($html, 'data-testid="equipment-chip"'));
        self::assertStringNotContainsString('data-testid="equipment-show-more"', $html);

        // Sélection d'un équipement supplémentaire : le menu RESTE ouvert
        // pour enchaîner (le re-rendu live ne le referme plus).
        [$props, $html] = $this->action($url, $csrf, $props, 'chooseEquipment', ['equipment' => 'gym'], 'dossier-show-search');
        self::assertTrue($props['equipmentExpanded'], 'Picking an equipment must keep the expanded list open.');
        self::assertSame($allAmenities, substr_count($html, 'data-testid="equipment-chip"'));
        self::assertStringNotContainsString('data-testid="equipment-show-more"', $html);
        self::assertStringContainsString('data-live-equipment-param="gym" aria-pressed="true"', preg_replace('/\s+/', ' ', $html));

        $this->em->clear();
        self::assertSame('gym', $this->em->find(Dossier::class, $dossier->getId())->getSearch()->getEquipment());
    }

    /**
     * @return array{0: string, 1: string|null, 2: array<string, mixed>}
     */
    private function extractComponent(Crawler $crawler, string $testId): array
    {
        $node = $crawler->filter('[data-testid="'.$testId.'"]')->first();
        self::assertGreaterThan(0, $node->count(), $testId.' component not found on the page.');

        return [
            (string) $node->attr('data-live-url-value'),
            $node->attr('data-live-csrf-value'),
            (array) json_decode((string) $node->attr('data-live-props-value'), true),
        ];
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $args
     *
     * @return array{0: array<string, mixed>, 1: string} props après re-rendu + HTML
     */
    private function action(string $url, ?string $csrf, array $props, string $action, array $args, string $testId): array
    {
        $this->client->request(
            'POST',
            $url.'/'.$action,
            ['data' => json_encode(['props' => $props, 'args' => $args])],
            [],
            array_filter([
                'HTTP_ACCEPT' => 'application/vnd.live-component+html',
                'HTTP_X_CSRF_TOKEN' => $csrf,
            ]),
        );
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));

        $html = (string) $response->getContent();
        $root = (new Crawler($html))->filter('[data-testid="'.$testId.'"]')->first();

        return [(array) json_decode((string) $root->attr('data-live-props-value'), true), $html];
    }

    private function persistDossier(): Dossier
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')->setLastName('Dupont')
            ->setEmail('jean@lock-flow.example')
            ->setLanguage(\App\Dossier\Domain\ContactLanguage::FR)
            ->setPrimaryContact(true);
        $dossier = (new Dossier())
            ->setName('Dupont')
            ->setReference('DS-000077')
            ->setPairingCode('LCKFL7')
            ->setCreatedAt(new \DateTimeImmutable())
            // Personnes validée : l'onglet Recherche est rendu sur la page.
            ->addValidatedStep(DossierStep::Persons)
            ->addPerson($tenant)
            ->setSearch((new DossierSearch())
                ->setBudget(2000)
                ->setAreas('11e')
                ->setMoveInAt(new \DateTimeImmutable('+2 months'))
                ->setPropertyType('t2')
                ->setStayDuration('long')
                ->setFurnishing('furnished')
                ->setGuarantorType('physical'));
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function dossierUrl(Dossier $dossier): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/dossiers/'.$dossier->getReference();
    }
}
