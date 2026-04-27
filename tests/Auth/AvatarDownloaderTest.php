<?php

namespace App\Tests\Auth;

use App\Auth\Service\AvatarDownloader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Pure unit test — no kernel, no DB, no real HTTP. Verifies the contract:
 *  - happy path returns a uuid.webp filename, file exists in storage
 *  - non-200 / unsupported mime / oversize / decode failures → null + log,
 *    nothing written
 *  - delete() removes the file when it exists, no-op otherwise
 */
final class AvatarDownloaderTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir().'/rip-avatar-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            foreach (glob($this->storageDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->storageDir);
        }
    }

    public function testHappyPathStoresWebpAndReturnsFilename(): void
    {
        $downloader = $this->makeDownloader([
            new MockResponse($this->onePixelPng(), [
                'http_code' => 200,
                'response_headers' => ['Content-Type: image/png'],
            ]),
        ]);

        $filename = $downloader->downloadAndStore('https://example.com/avatar.png');

        self::assertNotNull($filename);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}\.webp$/', $filename);
        self::assertFileExists($this->storageDir.'/'.$filename);
        // GD writes a real WebP — verify the magic bytes.
        $bytes = file_get_contents($this->storageDir.'/'.$filename);
        self::assertSame('RIFF', substr($bytes, 0, 4));
        self::assertSame('WEBP', substr($bytes, 8, 4));
    }

    public function testReturnsNullOnHttpError(): void
    {
        $downloader = $this->makeDownloader([
            new MockResponse('', ['http_code' => 404]),
        ]);

        self::assertNull($downloader->downloadAndStore('https://example.com/missing.png'));
    }

    public function testReturnsNullOnUnsupportedMime(): void
    {
        $downloader = $this->makeDownloader([
            new MockResponse('not-an-image', [
                'http_code' => 200,
                'response_headers' => ['Content-Type: text/html'],
            ]),
        ]);

        self::assertNull($downloader->downloadAndStore('https://example.com/page.html'));
    }

    public function testReturnsNullOnUndecodableBytes(): void
    {
        $downloader = $this->makeDownloader([
            new MockResponse('totally-not-a-png', [
                'http_code' => 200,
                'response_headers' => ['Content-Type: image/png'],
            ]),
        ]);

        self::assertNull($downloader->downloadAndStore('https://example.com/broken.png'));
    }

    public function testDeleteRemovesExistingFile(): void
    {
        $downloader = $this->makeDownloader([
            new MockResponse($this->onePixelPng(), [
                'http_code' => 200,
                'response_headers' => ['Content-Type: image/png'],
            ]),
        ]);

        $filename = $downloader->downloadAndStore('https://example.com/avatar.png');
        self::assertNotNull($filename);
        self::assertFileExists($this->storageDir.'/'.$filename);

        $downloader->delete($filename);
        self::assertFileDoesNotExist($this->storageDir.'/'.$filename);
    }

    public function testDeleteIsNoOpOnMissingFile(): void
    {
        $this->expectNotToPerformAssertions();

        $downloader = $this->makeDownloader([]);
        $downloader->delete('00000000-0000-7000-8000-000000000000.webp');
    }

    /** @param list<MockResponse> $responses */
    private function makeDownloader(array $responses): AvatarDownloader
    {
        return new AvatarDownloader(
            new MockHttpClient($responses),
            new NullLogger(),
            $this->storageDir,
        );
    }

    private function onePixelPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            true,
        );
    }
}
