<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Service\DossierDriveProvisioner;
use App\Dossier\Service\DriveDocumentStorage;
use App\Tests\Shared\Google\FakeDriveGateway;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Drive document storage + provisioner against an in-memory gateway (no
 * HTTP, no DB): folder-per-dossier / sub-folder-per-person layout, piece
 * naming, stream round-trip, and manager read-share sync.
 */
final class DossierDriveStorageTest extends TestCase
{
    public function testStoreProvisionsTheDossierAndPersonFoldersThenUploadsUnderTheTypeName(): void
    {
        $gateway = new FakeDriveGateway();
        $storage = $this->storage($gateway);
        [$dossier, $document] = $this->fixture();

        $fileId = $storage->store($dossier, $document, $this->upload());

        // The context root, then the dossier folder, then the person sub-folder.
        self::assertCount(3, $gateway->createdFolders);
        self::assertSame('Dossiers', $gateway->createdFolders[0]['name']);
        self::assertSame('shared-drive-root', $gateway->createdFolders[0]['parent']);
        self::assertSame('DS-000042 Martin', $gateway->createdFolders[1]['name']);
        self::assertSame($gateway->createdFolders[0]['id'], $gateway->createdFolders[1]['parent'], 'Dossiers hang under the context root, not the drive root.');
        self::assertSame('Martin Jean', $gateway->createdFolders[2]['name']);
        self::assertSame($dossier->getDriveFolderId(), $gateway->createdFolders[2]['parent']);

        // The piece is uploaded under the person folder, named by its type.
        self::assertCount(1, $gateway->uploads);
        self::assertSame('Bulletins de salaire.pdf', $gateway->uploads[0]['name']);
        self::assertSame($document->getPerson()?->getDriveFolderId(), $gateway->uploads[0]['parent']);
        self::assertSame('DS-000042', $gateway->uploads[0]['props']['dossierReference']);
        self::assertSame($fileId, array_key_first($gateway->contents));
    }

    public function testSecondFileOfTheSamePieceGetsASuffix(): void
    {
        $gateway = new FakeDriveGateway();
        $storage = $this->storage($gateway);
        [$dossier, $document] = $this->fixture();

        $storage->store($dossier, $document, $this->upload());
        // Simulate the first file already persisted on the document.
        $document->addFile((new DossierDocumentFile())->setStoredName('file-existing'));
        $storage->store($dossier, $document, $this->upload());

        self::assertSame('Bulletins de salaire.pdf', $gateway->uploads[0]['name']);
        self::assertSame('Bulletins de salaire-2.pdf', $gateway->uploads[1]['name']);
        // The root, dossier and person folders are reused, not recreated.
        self::assertCount(3, $gateway->createdFolders);
    }

    public function testStoreThrowsWhenDriveIsUnavailable(): void
    {
        $storage = $this->storage(new FakeDriveGateway(configured: false));
        [$dossier, $document] = $this->fixture();

        $this->expectException(\RuntimeException::class);
        $storage->store($dossier, $document, $this->upload());
    }

    public function testReadStreamAndDeleteRoundTripByFileId(): void
    {
        $gateway = new FakeDriveGateway();
        $storage = $this->storage($gateway);
        [$dossier, $document] = $this->fixture();

        $fileId = $storage->store($dossier, $document, $this->upload());
        $file = (new DossierDocumentFile())->setStoredName($fileId);

        self::assertTrue($storage->exists($dossier, $file));
        $stream = $storage->readStream($dossier, $file);
        self::assertSame('%PDF-1.4 fake', stream_get_contents($stream));
        fclose($stream);

        $storage->delete($dossier, $file);
        self::assertSame([$fileId], $gateway->deleted);
        self::assertFalse($storage->exists($dossier, $file));
    }

    public function testManagerShareGrantsReadAndRevokesThePreviousGrant(): void
    {
        $gateway = new FakeDriveGateway();
        $provisioner = $this->provisioner($gateway);
        $dossier = $this->dossierWithFolder('folder-root');

        // First manager: a read permission is created and remembered.
        $dossier->setManager($this->user('alice@rip.test'));
        $provisioner->syncManagerShare($dossier);
        self::assertSame([['file' => 'folder-root', 'email' => 'alice@rip.test']], $gateway->shares);
        $firstPermission = $dossier->getDriveManagerPermissionId();
        self::assertNotNull($firstPermission);

        // Reassigning revokes the previous permission and grants the new one.
        $dossier->setManager($this->user('bob@rip.test'));
        $provisioner->syncManagerShare($dossier);
        self::assertSame([['file' => 'folder-root', 'permission' => $firstPermission]], $gateway->revocations);
        self::assertSame('bob@rip.test', $gateway->shares[1]['email']);

        // Unassigning revokes and clears the stored permission.
        $dossier->setManager(null);
        $provisioner->syncManagerShare($dossier);
        self::assertNull($dossier->getDriveManagerPermissionId());
        self::assertCount(2, $gateway->revocations);
    }

    public function testProvisioningIsNoOpWhenDriveIsOff(): void
    {
        $gateway = new FakeDriveGateway(configured: false);
        $provisioner = $this->provisioner($gateway);
        $dossier = $this->dossierWithFolder(null);
        $dossier->setManager($this->user('alice@rip.test'));

        self::assertNull($provisioner->ensureDossierFolder($dossier));
        $provisioner->syncManagerShare($dossier);
        self::assertSame([], $gateway->shares);
        self::assertNull($dossier->getDriveFolderId());
    }

    private function storage(FakeDriveGateway $gateway): DriveDocumentStorage
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Bulletins de salaire');

        return new DriveDocumentStorage($gateway, $this->provisioner($gateway), $translator);
    }

    private function provisioner(FakeDriveGateway $gateway): DossierDriveProvisioner
    {
        return new DossierDriveProvisioner($gateway, $this->createStub(EntityManagerInterface::class), new NullLogger());
    }

    /**
     * @return array{0: Dossier, 1: DossierDocument}
     */
    private function fixture(): array
    {
        $person = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Martin')
            ->setEmail('jean@rip.test');

        $dossier = (new Dossier())->setReference('DS-000042')->setName('Martin');
        $dossier->addPerson($person);

        $document = (new DossierDocument())->setType(DossierDocumentType::Payslips)->setPerson($person);

        return [$dossier, $document];
    }

    private function dossierWithFolder(?string $folderId): Dossier
    {
        return (new Dossier())->setReference('DS-000099')->setName('Durand')->setDriveFolderId($folderId);
    }

    private function user(string $email): User
    {
        return (new User())->setEmail($email)->setFirstName('X')->setLastName('Y')->setCreatedAt(new \DateTimeImmutable());
    }

    private function upload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'drive-test');
        file_put_contents((string) $tmp, '%PDF-1.4 fake');

        return new UploadedFile((string) $tmp, 'ma piece.pdf', 'application/pdf', test: true);
    }
}
