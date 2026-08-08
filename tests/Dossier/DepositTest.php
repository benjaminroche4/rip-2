<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Entity\DossierPerson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Public deposit page: pairing with a dossier person email + the dossier
 * pairing code, then uploading the requested pieces. Every paired person of
 * the dossier sees all requested pieces (cross-deposit for couples and
 * guarantors) and the grant lives in the session, never in the URL.
 */
final class DepositTest extends WebTestCase
{
    private const TURBO_ACCEPT = 'text/vnd.turbo-stream.html, text/html';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', 'advisor@example.com')
            ->execute();

        $this->storageDir = (string) $container->getParameter('dossier_storage_dir');
        (new Filesystem())->remove($this->storageDir);
    }

    public function testPairingPageRendersTheFormAndIsNeverCached(): void
    {
        $this->client->request('GET', '/fr/depot-de-pieces');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#deposit-pairing');
        self::assertSelectorExists('form[name="deposit_pairing"]');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        $response = $this->client->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('X-LiteSpeed-Cache-Control'));
    }

    public function testPairingCodeIsPrefilledFromTheEmailedLink(): void
    {
        $crawler = $this->client->request('GET', '/fr/depot-de-pieces?code=abe78l');

        self::assertResponseIsSuccessful();
        self::assertSame('ABE78L', $crawler->filter('form[name="deposit_pairing"] input[name="deposit_pairing[code]"]')->attr('value'));
    }

    public function testPairingWithValidEmailAndCodeShowsTheWholeDossierDocuments(): void
    {
        $this->persistDossier();

        $this->pair('marie.durand@example.com', 'ABE78L');

        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();

        // Marie (follow-up) sees Jean's requested pieces: cross-deposit.
        self::assertSelectorExists('#deposit-documents');
        self::assertCount(2, $this->visibleRegion($crawler)->filter('[data-testid="deposit-document"]'));
        self::assertStringContainsString('Jean Dupont', $crawler->filter('#deposit-documents')->text());
        self::assertStringContainsString('DS-000042', $crawler->filter('#deposit-documents')->text());
    }

    public function testPairingIsCaseInsensitiveOnEmailAndCode(): void
    {
        $this->persistDossier();

        $this->pair('JEAN.DUPONT@example.com', 'abe78l');

        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorExists('#deposit-documents');
    }

    public function testPairingFailsWithTheSameGenericErrorForWrongCodeOrWrongEmail(): void
    {
        $this->persistDossier();

        // Wrong code, valid email.
        $this->pair('jean.dupont@example.com', 'ZZZZZZ');
        $wrongCode = $this->client->getResponse()->getContent();
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 422]);

        // Valid code, email not on the dossier.
        $this->pair('stranger@example.com', 'ABE78L');
        $wrongEmail = $this->client->getResponse()->getContent();

        self::assertStringContainsString('ne correspondent à aucun dossier', (string) $wrongCode);
        self::assertStringContainsString('ne correspondent à aucun dossier', (string) $wrongEmail);

        // No grant: the deposit page still shows the pairing form.
        $this->client->request('GET', '/fr/depot-de-pieces');
        self::assertSelectorExists('#deposit-pairing');
    }

    public function testPairingErrorsComeBackAsTurboStreamWithStatus200(): void
    {
        $this->persistDossier();

        $this->pair('jean.dupont@example.com', 'ZZZZZZ', turbo: true);

        self::assertResponseStatusCodeSame(200);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="deposit-pairing">', $content);
    }

    public function testPairingHoneypotRejectsBots(): void
    {
        $this->persistDossier();

        $this->pair('jean.dupont@example.com', 'ABE78L', website: 'https://spam.example');

        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 422]);
        $this->client->request('GET', '/fr/depot-de-pieces');
        self::assertSelectorExists('#deposit-pairing');
    }

    public function testUploadStoresTheFileAndMarksThePieceReceived(): void
    {
        $this->persistDossier();
        $this->pair('jean.dupont@example.com', 'ABE78L');
        $crawler = $this->client->followRedirect();

        $form = $crawler->filter('[data-testid="deposit-document"]')->first()->filter('form')->form();
        $form['file']->upload($this->makePdf());
        $this->client->submit($form, [], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);

        self::assertResponseStatusCodeSame(200);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="deposit-documents">', $content);

        $this->em->clear();
        /** @var Dossier $dossier */
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['reference' => 'DS-000042']);
        $document = $dossier->getPersons()->first()->getDocuments()->first();
        self::assertSame(DossierDocumentStatus::Received, $document->getStatus());
        self::assertNotNull($document->getReceivedAt());
        self::assertCount(1, $document->getFiles());

        /** @var DossierDocumentFile $file */
        $file = $document->getFiles()->first();
        self::assertSame('application/pdf', $file->getMimeType());
        self::assertStringEndsWith('.pdf', (string) $file->getStoredName());
        // The original client name is kept for display but never used on disk.
        self::assertSame('piece.pdf', $file->getOriginalName());
        self::assertFileExists($this->storageDir.'/DS-000042/'.$file->getStoredName());
    }

    public function testUploadRejectsUnsupportedFileTypes(): void
    {
        $this->persistDossier();
        $this->pair('jean.dupont@example.com', 'ABE78L');
        $crawler = $this->client->followRedirect();

        $path = tempnam(sys_get_temp_dir(), 'deposit').'.txt';
        file_put_contents($path, 'just some text');
        $form = $crawler->filter('[data-testid="deposit-document"]')->first()->filter('form')->form();
        $form['file']->upload($path);
        $this->client->submit($form, [], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);

        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        /** @var Dossier $dossier */
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['reference' => 'DS-000042']);
        $document = $dossier->getPersons()->first()->getDocuments()->first();
        self::assertSame(DossierDocumentStatus::Requested, $document->getStatus());
        self::assertCount(0, $document->getFiles());
    }

    public function testUploadWithoutPairingRedirectsToThePairingForm(): void
    {
        $this->persistDossier();
        /** @var Dossier $dossier */
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['reference' => 'DS-000042']);
        $documentId = $dossier->getPersons()->first()->getDocuments()->first()->getId();

        $this->client->request('POST', '/fr/depot-de-pieces/'.$documentId);

        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('/fr/depot-de-pieces', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testUploadRefusesADocumentFromAnotherDossier(): void
    {
        $this->persistDossier();

        // A second dossier with its own requested piece.
        $otherTenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Paul')->setLastName('Martin')
            ->setEmail('paul.martin@example.com')
            ->setPrimaryContact(true);
        $otherTenant->addDocument((new DossierDocument())
            ->setType(DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable()));
        $other = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-000043')
            ->setPairingCode('QQQ11Q')
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($otherTenant);
        $this->em->persist($other);
        $this->em->flush();
        $foreignDocumentId = (int) $otherTenant->getDocuments()->first()->getId();

        // Paired on DS-000042, posting against DS-000043's document.
        $this->pair('jean.dupont@example.com', 'ABE78L');
        $crawler = $this->client->followRedirect();
        $token = $crawler->filter('[data-testid="deposit-document"] input[name="_token"]')->first()->attr('value');

        $this->client->request(
            'POST',
            '/fr/depot-de-pieces/'.$foreignDocumentId,
            ['_token' => $token],
            ['file' => new UploadedFile($this->makePdf(), 'piece.pdf', 'application/pdf', test: true)],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testLeaveDropsThePairingGrant(): void
    {
        $this->persistDossier();
        $this->pair('jean.dupont@example.com', 'ABE78L');
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->filter('[data-testid="deposit-leave"]')->form());

        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorExists('#deposit-pairing');
    }

    public function testDepositPageShowsManagerStatusesAndDates(): void
    {
        $this->persistDossier();

        /** @var Dossier $dossier */
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['reference' => 'DS-000042']);

        $manager = (new User())
            ->setEmail('advisor@example.com')
            ->setFirstName('Alice')->setLastName('Advisor')
            ->setPhoneNumber('+33611223344')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($manager);
        $dossier->setManager($manager);

        $documents = $dossier->getPersons()->first()->getDocuments();
        $documents->first()
            ->setStatus(DossierDocumentStatus::Validated)
            ->setReceivedAt(new \DateTimeImmutable('2026-08-05'));
        $documents->last()
            ->setStatus(DossierDocumentStatus::Refused)
            ->setReceivedAt(new \DateTimeImmutable('2026-08-06'));
        $this->em->flush();

        $this->pair('jean.dupont@example.com', 'ABE78L');
        $crawler = $this->client->followRedirect();

        // No site header/footer: dedicated shell for the deposit flow.
        self::assertSelectorNotExists('header nav');
        self::assertSelectorNotExists('footer');

        // Dedicated advisor card with contact links.
        self::assertStringContainsString('Alice Advisor', $crawler->filter('[data-testid="deposit-manager"]')->text());
        self::assertSelectorExists('[data-testid="deposit-manager"] a[href="mailto:advisor@example.com"]');
        self::assertSelectorExists('[data-testid="deposit-manager"] a[href="tel:+33611223344"]');

        // Statuses and deposit dates per piece.
        $statuses = $crawler->filter('[data-testid="deposit-document-status"]')->each(static fn ($node) => $node->text());
        self::assertContains('Validée', $statuses);
        self::assertContains('Refusée', $statuses);
        $dates = $crawler->filter('[data-testid="deposit-document-date"]')->each(static fn ($node) => $node->text());
        self::assertStringContainsString('05.08.2026', implode(' ', $dates));
        self::assertStringContainsString('06.08.2026', implode(' ', $dates));

        // Refused piece: hint + upload still possible; validated piece: no
        // upload form anymore. Two documents, one uploadable.
        self::assertSelectorExists('[data-testid="deposit-document-refused"]');
        self::assertCount(1, $this->visibleRegion($crawler)->filter('[data-testid="deposit-file-input"]'));

        // Progress ring: 1 of 2 validated.
        self::assertStringContainsString('1/2', $crawler->filter('[data-testid="deposit-progress"]')->text());

        // Trust strip present on the paired state.
        self::assertSelectorExists('[data-testid="deposit-trust"]');
    }

    public function testRefusalReasonAndDescriptionAreShownToTheTenant(): void
    {
        $this->persistDossier();
        /** @var Dossier $dossier */
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['reference' => 'DS-000042']);
        $documents = $dossier->getPersons()->first()->getDocuments();
        $documents->first()->setDescription('Recto-verso, en cours de validité');
        $documents->last()
            ->setStatus(DossierDocumentStatus::Refused)
            ->setRefusalReason('Il manque le mois de juillet')
            ->setReceivedAt(new \DateTimeImmutable('2026-08-06'));
        $this->em->flush();

        $this->pair('jean.dupont@example.com', 'ABE78L');
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Recto-verso, en cours de validité', $crawler->filter('[data-testid="deposit-document-description"]')->text());
        self::assertStringContainsString('Il manque le mois de juillet', $crawler->filter('[data-testid="deposit-document-refused"]')->text());
    }

    public function testPairedPersonViewsAndDeletesTheirOwnFile(): void
    {
        $this->persistDossier();
        $this->pair('jean.dupont@example.com', 'ABE78L');
        $crawler = $this->client->followRedirect();

        // Deposit a file on the first piece.
        $form = $crawler->filter('[data-testid="deposit-document"]')->first()->filter('form')->form();
        $form['file']->upload($this->makePdf());
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();

        // The vignette links to an inline view of the file.
        $viewHref = (string) $this->visibleRegion($crawler)->filter('[data-testid="deposit-file-view"]')->attr('href');
        $this->client->request('GET', $viewHref);
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        // Deleting the only file puts the piece back to "requested".
        $crawler = $this->client->request('GET', '/fr/depot-de-pieces');
        $this->client->submit($this->visibleRegion($crawler)->filter('[data-testid="deposit-file-delete"]')->closest('form')->form());
        $this->em->clear();
        /** @var Dossier $dossier */
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['reference' => 'DS-000042']);
        $document = $dossier->getPersons()->first()->getDocuments()->first();
        self::assertCount(0, $document->getFiles());
        self::assertSame(DossierDocumentStatus::Requested, $document->getStatus());
        self::assertNull($document->getReceivedAt());
    }

    public function testValidatedPieceFilesCannotBeDeleted(): void
    {
        $this->persistDossier();
        $this->pair('jean.dupont@example.com', 'ABE78L');
        $crawler = $this->client->followRedirect();

        // A file on each of the two pieces.
        foreach ([0, 1] as $index) {
            $form = $crawler->filter('[data-testid="deposit-document"]')->eq($index)->filter('form')->form();
            $form['file']->upload($this->makePdf());
            $this->client->submit($form);
            $crawler = $this->client->request('GET', '/fr/depot-de-pieces');
        }

        $this->em->clear();
        /** @var Dossier $dossier */
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['reference' => 'DS-000042']);
        $validated = $dossier->getPersons()->first()->getDocuments()->first();
        $validated->setStatus(DossierDocumentStatus::Validated);
        $this->em->flush();
        $lockedFileId = (int) $validated->getFiles()->first()->getId();

        // The validated piece has no delete button; the other one still has.
        $crawler = $this->client->request('GET', '/fr/depot-de-pieces');
        self::assertCount(1, $this->visibleRegion($crawler)->filter('[data-testid="deposit-file-delete"]'));

        // Posting against the locked file with a valid token (borrowed from
        // the other piece's delete form) changes nothing.
        $token = (string) $this->visibleRegion($crawler)->filter('[data-testid="deposit-file-delete"]')->closest('form')->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/fr/depot-de-pieces/fichier/'.$lockedFileId.'/suppression', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);

        $this->em->clear();
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['reference' => 'DS-000042']);
        $fresh = $dossier->getPersons()->first()->getDocuments()->first();
        self::assertCount(1, $fresh->getFiles());
        self::assertSame(DossierDocumentStatus::Validated, $fresh->getStatus());
    }

    public function testLocaleSwitchKeepsThePrefilledCode(): void
    {
        $crawler = $this->client->request('GET', '/fr/depot-de-pieces?code=ABE78L');

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('[data-testid="deposit-locale-switch"] a')->each(static fn ($node) => $node->attr('href'));
        self::assertContains('/en/document-upload?code=ABE78L', $links);
        self::assertContains('/fr/depot-de-pieces?code=ABE78L', $links);
    }

    public function testPairingPageCarriesTheTrustStrip(): void
    {
        $this->client->request('GET', '/fr/depot-de-pieces');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="deposit-trust"]');
    }

    /**
     * While a ui.sh variant-comparison round is in progress parts of the
     * page are duplicated once per style option: counting selectors must
     * ignore the hidden branches. Strips them from the DOM and returns the
     * crawler, which is a no-op in the normal post-selection state.
     */
    private function visibleRegion(\Symfony\Component\DomCrawler\Crawler $crawler): \Symfony\Component\DomCrawler\Crawler
    {
        foreach ($crawler->filter('[data-uidotsh-option][hidden]') as $node) {
            $node->parentNode?->removeChild($node);
        }

        return $crawler;
    }

    private function pair(string $email, string $code, bool $turbo = false, string $website = ''): void
    {
        $crawler = $this->client->request('GET', '/fr/depot-de-pieces');
        $form = $crawler->filter('form[name="deposit_pairing"]')->form([
            'deposit_pairing[email]' => $email,
            'deposit_pairing[code]' => $code,
            'deposit_pairing[website]' => $website,
        ]);

        $this->client->submit($form, [], $turbo ? ['HTTP_ACCEPT' => self::TURBO_ACCEPT] : []);
    }

    private function makePdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'deposit').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        // The crawler needs the display name: rename so the client original
        // name is deterministic.
        $named = \dirname($path).'/piece.pdf';
        copy($path, $named);

        return $named;
    }

    private function persistDossier(): void
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('jean.dupont@example.com')
            ->setPrimaryContact(true);
        $tenant->addDocument((new DossierDocument())
            ->setType(DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable()));
        $tenant->addDocument((new DossierDocument())
            ->setType(DossierDocumentType::Payslips)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable()));

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
            ->addPerson($followUp);

        $this->em->persist($dossier);
        $this->em->flush();
    }
}
