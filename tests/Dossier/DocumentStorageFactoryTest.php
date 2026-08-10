<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Service\DocumentStorageFactory;
use App\Dossier\Service\LocalDocumentStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Driver selection contract: 'local' by default, 'gcs' requires a bucket,
 * anything else is a configuration error.
 */
final class DocumentStorageFactoryTest extends TestCase
{
    public function testLocalDriverReturnsTheDiskStorage(): void
    {
        $local = new LocalDocumentStorage('/tmp/does-not-matter', new Filesystem());

        self::assertSame($local, (new DocumentStorageFactory($local, 'local'))->create());
    }

    public function testGcsDriverWithoutBucketIsAConfigurationError(): void
    {
        $local = new LocalDocumentStorage('/tmp/does-not-matter', new Filesystem());

        $this->expectException(\InvalidArgumentException::class);
        (new DocumentStorageFactory($local, 'gcs', bucket: ''))->create();
    }

    public function testUnknownDriverIsAConfigurationError(): void
    {
        $local = new LocalDocumentStorage('/tmp/does-not-matter', new Filesystem());

        $this->expectException(\InvalidArgumentException::class);
        (new DocumentStorageFactory($local, 's3'))->create();
    }
}
