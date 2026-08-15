<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Service\GcsDocumentStorage;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * GcsDocumentStorage contract against a mocked bucket: object naming under
 * dossiers/<reference>/, UUID-based stored names, streaming reads and
 * idempotent deletes. No network involved.
 */
final class GcsDocumentStorageTest extends TestCase
{
    public function testStoreUploadsUnderTheDossierNamespaceWithAUuidName(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gcs-test');
        file_put_contents($tmp, '%PDF-1.4 fake');
        $upload = new UploadedFile($tmp, 'ma piece.pdf', 'application/pdf', test: true);

        $captured = [];
        $bucket = $this->createMock(Bucket::class);
        $bucket->expects(self::once())->method('upload')
            ->willReturnCallback(function ($stream, array $options) use (&$captured) {
                $captured = $options;

                return $this->createStub(StorageObject::class);
            });

        $storedName = (new GcsDocumentStorage($bucket))->store($this->dossier(), new DossierDocument(), $upload);

        // UUID + extension, the client file name never reaches the bucket.
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}\.pdf$/', $storedName);
        self::assertSame('dossiers/DS-000042/documents/'.$storedName, $captured['name']);
        self::assertSame('application/pdf', $captured['metadata']['contentType']);
        @unlink($tmp);
    }

    public function testReadStreamExposesTheObjectContentAsAResource(): void
    {
        $object = $this->createStub(StorageObject::class);
        $object->method('downloadAsStream')->willReturn(Utils::streamFor('file-content'));
        $bucket = $this->createMock(Bucket::class);
        $bucket->expects(self::once())->method('object')
            ->with('dossiers/DS-000042/documents/abc.pdf')
            ->willReturn($object);

        $stream = (new GcsDocumentStorage($bucket))->readStream($this->dossier(), $this->file('abc.pdf'));

        self::assertSame('file-content', stream_get_contents($stream));
        fclose($stream);
    }

    public function testDeleteIsIdempotentOnMissingObjects(): void
    {
        $object = $this->createMock(StorageObject::class);
        $object->method('exists')->willReturn(false);
        $object->expects(self::never())->method('delete');
        $bucket = $this->createStub(Bucket::class);
        $bucket->method('object')->willReturn($object);

        (new GcsDocumentStorage($bucket))->delete($this->dossier(), $this->file('abc.pdf'));
    }

    public function testDeleteRemovesAnExistingObject(): void
    {
        $object = $this->createMock(StorageObject::class);
        $object->method('exists')->willReturn(true);
        $object->expects(self::once())->method('delete');
        $bucket = $this->createStub(Bucket::class);
        $bucket->method('object')->willReturn($object);

        (new GcsDocumentStorage($bucket))->delete($this->dossier(), $this->file('abc.pdf'));
    }

    private function dossier(): Dossier
    {
        return (new Dossier())->setReference('DS-000042');
    }

    private function file(string $storedName): DossierDocumentFile
    {
        return (new DossierDocumentFile())->setStoredName($storedName);
    }
}
