<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Service\DocumentStorageFactory;
use App\Dossier\Service\DossierDriveProvisioner;
use App\Dossier\Service\DriveDocumentStorage;
use App\Dossier\Service\LocalDocumentStorage;
use App\Shared\Gcs\GcsBucketFactory;
use App\Tests\Shared\Google\FakeDriveGateway;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Driver selection contract: 'local' by default, 'gcs' requires a bucket,
 * 'drive' returns the Drive storage, anything else is a configuration error.
 */
final class DocumentStorageFactoryTest extends TestCase
{
    public function testLocalDriverReturnsTheDiskStorage(): void
    {
        $local = new LocalDocumentStorage('/tmp/does-not-matter', new Filesystem());

        self::assertSame($local, (new DocumentStorageFactory($local, new GcsBucketFactory('', ''), $this->drive(), 'local'))->create());
    }

    public function testGcsDriverWithoutBucketIsAConfigurationError(): void
    {
        $local = new LocalDocumentStorage('/tmp/does-not-matter', new Filesystem());

        // No bucket configured: the shared factory refuses to build a client.
        $this->expectException(\InvalidArgumentException::class);
        (new DocumentStorageFactory($local, new GcsBucketFactory('', ''), $this->drive(), 'gcs'))->create();
    }

    public function testDriveDriverReturnsTheDriveStorage(): void
    {
        $local = new LocalDocumentStorage('/tmp/does-not-matter', new Filesystem());
        $drive = $this->drive();

        self::assertSame($drive, (new DocumentStorageFactory($local, new GcsBucketFactory('', ''), $drive, 'drive'))->create());
    }

    public function testUnknownDriverIsAConfigurationError(): void
    {
        $local = new LocalDocumentStorage('/tmp/does-not-matter', new Filesystem());

        $this->expectException(\InvalidArgumentException::class);
        (new DocumentStorageFactory($local, new GcsBucketFactory('', ''), $this->drive(), 's3'))->create();
    }

    private function drive(): DriveDocumentStorage
    {
        $gateway = new FakeDriveGateway(configured: false);
        $provisioner = new DossierDriveProvisioner($gateway, $this->createStub(EntityManagerInterface::class), new NullLogger());

        return new DriveDocumentStorage($gateway, $provisioner, $this->createStub(TranslatorInterface::class));
    }
}
