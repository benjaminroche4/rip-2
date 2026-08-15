<?php

declare(strict_types=1);

namespace App\Tests\Admin;

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

/**
 * Download route of deposited dossier files: files live outside public/ and
 * are streamed through the admin firewall only.
 */
final class DossierDocumentFileAccessTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'dossier-file-test-admin@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminPrefix;
    private string $storageDir;
    private int $fileId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->storageDir = (string) $container->getParameter('dossier_storage_dir');

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::ADMIN_EMAIL)
            ->execute();

        $admin = (new User())
            ->setEmail(self::ADMIN_EMAIL)
            ->setFirstName('Test')->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);

        $document = (new DossierDocument())
            ->setType(DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Received)
            ->setRequestedAt(new \DateTimeImmutable())
            ->setReceivedAt(new \DateTimeImmutable());
        $file = (new DossierDocumentFile())
            ->setStoredName('stored-test.pdf')
            ->setOriginalName('passeport jean.pdf')
            ->setMimeType('application/pdf')
            ->setSize(9)
            ->setUploadedAt(new \DateTimeImmutable());
        $document->addFile($file);

        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')->setLastName('Dupont')
            ->setEmail('jean.dupont@example.com')
            ->setPrimaryContact(true);
        $tenant->addDocument($document);

        $dossier = (new Dossier())
            ->setName('Famille Dupont')
            ->setReference('DS-000042')
            ->setPairingCode('ABE78L')
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant);
        $this->em->persist($dossier);
        $this->em->flush();
        $this->fileId = (int) $file->getId();

        $filesystem = new Filesystem();
        $filesystem->remove($this->storageDir);
        $filesystem->mkdir($this->storageDir.'/DS-000042/documents');
        file_put_contents($this->storageDir.'/DS-000042/documents/stored-test.pdf', '%PDF-1.4');
    }

    private function fileUrl(int $id, string $reference = 'DS-000042'): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/dossiers/'.$reference.'/fichiers/'.$id;
    }

    public function testAdminStreamsTheFileInlineAndUncached(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', $this->fileUrl($this->fileId));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('X-LiteSpeed-Cache-Control'));
    }

    public function testDownloadQueryForcesAttachment(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', $this->fileUrl($this->fileId).'?download=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', $this->fileUrl($this->fileId));

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('connexion', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testFileFromAnotherDossierReferenceIs404(): void
    {
        // Second dossier with no files: its reference must not unlock the
        // first dossier's file id.
        $other = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-000043')
            ->setPairingCode('QQQ11Q')
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson((new DossierPerson())
                ->setRole(DossierPersonRole::TENANT)
                ->setFirstName('Paul')->setLastName('Martin')
                ->setEmail('paul.martin@example.com')
                ->setPrimaryContact(true));
        $this->em->persist($other);
        $this->em->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', $this->fileUrl($this->fileId, 'DS-000043'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingFileOnDiskIs404(): void
    {
        (new Filesystem())->remove($this->storageDir.'/DS-000042/documents/stored-test.pdf');

        $this->loginAsAdmin();
        $this->client->request('GET', $this->fileUrl($this->fileId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminDownloadsTheWholeDossierAsZip(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/dossiers/DS-000042/pieces.zip');
        $content = $this->client->getInternalResponse()->getContent();

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        // Named after the primary tenant: self-explanatory once saved.
        self::assertStringContainsString('Dupont Jean - DS-000042.zip', (string) $response->headers->get('Content-Disposition'));
        // A zip stream starts with the PK signature.
        self::assertStringStartsWith('PK', (string) $content);
    }

    public function testZipRequiresAuthentication(): void
    {
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/dossiers/DS-000042/pieces.zip');

        self::assertResponseStatusCodeSame(302);
    }

    public function testZipOfDossierWithoutFilesIs404(): void
    {
        (new Filesystem())->remove($this->storageDir.'/DS-000042/documents/stored-test.pdf');

        $this->loginAsAdmin();
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/dossiers/DS-000042/pieces.zip');

        self::assertResponseStatusCodeSame(404);
    }

    private function loginAsAdmin(): void
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL])
            ?? throw new \RuntimeException('Test admin not found.');
        $this->client->loginUser($admin);
    }
}
