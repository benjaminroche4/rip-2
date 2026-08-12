<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\Storage\AvatarStorageFactory;
use App\Auth\Storage\GcsAvatarStorage;
use App\Auth\Storage\LocalAvatarStorage;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Avatar storage: the local disk round-trip, the driver factory, and the
 * GCS object path (user/avatar/<uuid>.webp) in the shared bucket.
 */
final class AvatarStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/rip-avatar-storage-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->dir);
        }
    }

    public function testLocalStoreReadDelete(): void
    {
        $storage = new LocalAvatarStorage($this->dir, new NullLogger());
        $ulid = (string) new \Symfony\Component\Uid\Ulid();

        $path = $storage->store($ulid, 'fake-webp-bytes');
        self::assertMatchesRegularExpression('#^users/'.$ulid.'/avatar/[0-9a-f-]{36}\.webp$#', $path);
        self::assertTrue($storage->exists($path));

        $stream = $storage->readStream($path);
        self::assertSame('fake-webp-bytes', stream_get_contents($stream));
        fclose($stream);

        $storage->delete($path);
        self::assertFalse($storage->exists($path));
    }

    public function testLocalRejectsAPathTraversalAttempt(): void
    {
        $storage = new LocalAvatarStorage($this->dir, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $storage->readStream('../../../etc/passwd');
    }

    public function testFactoryDefaultsToLocal(): void
    {
        $factory = new AvatarStorageFactory(new LocalAvatarStorage($this->dir, new NullLogger()), new \App\Shared\Gcs\GcsBucketFactory('', ''), 'local');

        self::assertInstanceOf(LocalAvatarStorage::class, $factory->create());
    }

    public function testGcsUploadsUnderTheUserAvatarPrefix(): void
    {
        $object = $this->createStub(StorageObject::class);
        $bucket = $this->createMock(Bucket::class);
        $bucket->expects(self::once())
            ->method('upload')
            ->with(
                'the-webp-bytes',
                self::callback(static fn (array $opts): bool => 1 === preg_match('#^users/[0-9A-Za-z]+/avatar/[0-9a-f-]{36}\.webp$#', (string) $opts['name'])
                    && 'image/webp' === $opts['metadata']['contentType']),
            )
            ->willReturn($object);

        $path = (new GcsAvatarStorage($bucket))->store('01HZY0AVATARULID0000000000', 'the-webp-bytes');
        self::assertMatchesRegularExpression('#^users/01HZY0AVATARULID0000000000/avatar/[0-9a-f-]{36}\.webp$#', $path);
    }

    public function testGcsDeleteTargetsTheUserAvatarPrefix(): void
    {
        $object = $this->createMock(StorageObject::class);
        $object->method('exists')->willReturn(true);
        $object->expects(self::once())->method('delete');

        $bucket = $this->createMock(Bucket::class);
        $bucket->expects(self::once())
            ->method('object')
            ->with('users/01HZY0AVATARULID0000000000/avatar/0192f000-0000-7000-8000-000000000000.webp')
            ->willReturn($object);

        (new GcsAvatarStorage($bucket))->delete('users/01HZY0AVATARULID0000000000/avatar/0192f000-0000-7000-8000-000000000000.webp');
    }
}
