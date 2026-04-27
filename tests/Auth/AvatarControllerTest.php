<?php

namespace App\Tests\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AvatarController invariants:
 *  - 404 on missing file (and on path-traversal attempts the router blocks)
 *  - 200 with immutable+max-age=1y for an existing avatar
 *  - 304 on second hit when If-None-Match matches the ETag
 */
final class AvatarControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $storageDir;
    private string $filename;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->storageDir = static::getContainer()->getParameter('kernel.project_dir').'/var/uploads/avatars';

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0o755, true);
        }

        // Tiny valid WebP-flagged file is hard to fixture. Use a 1×1 PNG renamed
        // to .webp — the controller streams bytes verbatim and only the route
        // regex enforces the .webp suffix, so this is sufficient for HTTP tests.
        $this->filename = Uuid::v7()->toRfc4122().'.webp';
        file_put_contents($this->storageDir.'/'.$this->filename, $this->onePixelPng());
    }

    protected function tearDown(): void
    {
        @unlink($this->storageDir.'/'.$this->filename);
    }

    public function testServesAvatarWithImmutableCacheHeaders(): void
    {
        $this->client->request('GET', '/avatars/'.$this->filename);

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertNotEmpty($this->client->getResponse()->headers->get('ETag'));
    }

    public function testReturns404OnMissingFile(): void
    {
        $missing = Uuid::v7()->toRfc4122().'.webp';
        $this->client->request('GET', '/avatars/'.$missing);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRouterRejectsPathTraversalAttempt(): void
    {
        // The route regex requires `<uuid>.webp` — anything else is a 404 from
        // the router, never reaches the controller.
        $this->client->request('GET', '/avatars/..%2F..%2Fetc%2Fpasswd');

        self::assertResponseStatusCodeSame(404);
    }

    public function testReturns304WhenETagMatches(): void
    {
        $this->client->request('GET', '/avatars/'.$this->filename);
        $etag = (string) $this->client->getResponse()->headers->get('ETag');

        $this->client->request('GET', '/avatars/'.$this->filename, [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }

    private function onePixelPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            true,
        );
    }
}
