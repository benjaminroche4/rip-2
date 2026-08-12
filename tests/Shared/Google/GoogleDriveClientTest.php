<?php

declare(strict_types=1);

namespace App\Tests\Shared\Google;

use App\Shared\Google\GoogleDriveClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * GoogleDriveClient against a mocked transport: the service-account JWT grant
 * runs for real (throwaway RSA key), every Drive call carries the Shared
 * Drive flags, and unconfigured clients never touch the network.
 */
final class GoogleDriveClientTest extends TestCase
{
    private string $keyFile;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \assert(false !== $key);
        openssl_pkey_export($key, $pem);
        $this->keyFile = (string) tempnam(sys_get_temp_dir(), 'gdrive-test-');
        file_put_contents($this->keyFile, (string) json_encode([
            'client_email' => 'visio@service-account.test',
            'private_key' => $pem,
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
    }

    public function testItStaysSilentWhenUnconfigured(): void
    {
        $http = new MockHttpClient(static function (): never {
            self::fail('No HTTP call expected when the client is unconfigured.');
        });
        $client = new GoogleDriveClient($http, new NullLogger(), '', '');

        self::assertFalse($client->isConfigured());
        self::assertNull($client->ensureFolder('DS-1', 'parent'));
        self::assertNull($client->uploadFile('f.pdf', 'parent', 'bytes', 'application/pdf'));
        self::assertNull($client->shareRead('file', 'a@b.test'));
        self::assertFalse($client->fileExists('file'));
        $client->deleteFile('file');
        $client->removePermission('file', 'perm');
    }

    public function testEnsureFolderReusesAnExistingFolderWithoutCreating(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'tok']));
            }

            // The list query finds a matching folder: no create call follows.
            return new MockResponse((string) json_encode(['files' => [['id' => 'existing-folder']]]));
        });
        $client = new GoogleDriveClient($http, new NullLogger(), $this->keyFile, 'shared-drive-id');

        self::assertSame('existing-folder', $client->ensureFolder('DS-000042 Martin', 'shared-drive-id'));
        self::assertCount(2, $requests); // token + list, no create
        [, $listUrl] = $requests[1];
        self::assertStringContainsString('driveId=shared-drive-id', $listUrl);
        self::assertStringContainsString('includeItemsFromAllDrives=true', $listUrl);
        self::assertStringContainsString('supportsAllDrives=true', $listUrl);
    }

    public function testEnsureFolderCreatesWhenNoneExists(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'tok']));
            }
            if ('GET' === $method) {
                return new MockResponse((string) json_encode(['files' => []]));
            }

            return new MockResponse((string) json_encode(['id' => 'new-folder']));
        });
        $client = new GoogleDriveClient($http, new NullLogger(), $this->keyFile, 'shared-drive-id');

        self::assertSame('new-folder', $client->ensureFolder('Martin Jean', 'parent-folder', ['dossierReference' => 'DS-000042']));

        [$method, $createUrl, $options] = $requests[2];
        self::assertSame('POST', $method);
        self::assertStringContainsString('supportsAllDrives=true', $createUrl);
        $body = json_decode((string) $options['body'], true);
        self::assertSame('application/vnd.google-apps.folder', $body['mimeType']);
        self::assertSame(['parent-folder'], $body['parents']);
        self::assertSame(['dossierReference' => 'DS-000042'], $body['appProperties']);
    }

    public function testUploadFileSendsMultipartAndReturnsTheId(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'tok']));
            }
            $captured = [$url, $options];

            return new MockResponse((string) json_encode(['id' => 'uploaded-file']));
        });
        $client = new GoogleDriveClient($http, new NullLogger(), $this->keyFile, 'shared-drive-id');

        $id = $client->uploadFile('Bulletins.pdf', 'person-folder', '%PDF bytes', 'application/pdf', ['pieceType' => 'payslips']);

        self::assertSame('uploaded-file', $id);
        [$url, $options] = $captured;
        self::assertStringContainsString('/upload/drive/v3/files', $url);
        self::assertStringContainsString('uploadType=multipart', $url);
        self::assertStringContainsString('supportsAllDrives=true', $url);
        self::assertStringContainsString('multipart/related; boundary=', implode("\n", $options['headers']));
        self::assertStringContainsString('%PDF bytes', (string) $options['body']);
        self::assertStringContainsString('"pieceType":"payslips"', (string) $options['body']);
    }

    public function testShareReadCreatesAReaderPermission(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'tok']));
            }
            $captured = [$url, $options];

            return new MockResponse((string) json_encode(['id' => 'perm-1']));
        });
        $client = new GoogleDriveClient($http, new NullLogger(), $this->keyFile, 'shared-drive-id');

        self::assertSame('perm-1', $client->shareRead('folder-id', 'manager@rip.test'));
        [$url, $options] = $captured;
        self::assertStringContainsString('/files/folder-id/permissions', $url);
        self::assertStringContainsString('sendNotificationEmail=false', $url);
        $body = json_decode((string) $options['body'], true);
        self::assertSame(['role' => 'reader', 'type' => 'user', 'emailAddress' => 'manager@rip.test'], $body);
    }

    public function testDownloadStreamReturnsTheFileContent(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'tok']));
            }

            return new MockResponse('the-pdf-bytes');
        });
        $client = new GoogleDriveClient($http, new NullLogger(), $this->keyFile, 'shared-drive-id');

        $stream = $client->downloadStream('file-id');
        self::assertSame('the-pdf-bytes', stream_get_contents($stream));
        fclose($stream);
    }
}
