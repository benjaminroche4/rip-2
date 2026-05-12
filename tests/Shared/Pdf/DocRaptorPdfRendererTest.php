<?php

declare(strict_types=1);

namespace App\Tests\Shared\Pdf;

use App\Shared\Pdf\DocRaptorPdfRenderer;
use App\Shared\Pdf\PdfFormat;
use App\Shared\Pdf\PdfOptions;
use App\Shared\Pdf\PdfOrientation;
use App\Shared\Pdf\PdfRenderException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Locks the DocRaptor wire contract: the JSON envelope sent to the API
 * matches what Prince expects (page_size, margins, test flag), and
 * non-2xx responses surface as PdfRenderException.
 *
 * Uses MockHttpClient so no real network call is made — the test stays
 * deterministic and fast.
 */
final class DocRaptorPdfRendererTest extends TestCase
{
    public function testReturnsPdfBytesOn200(): void
    {
        $fakePdf = '%PDF-1.4 fake body';
        $mockClient = new MockHttpClient(new MockResponse($fakePdf, [
            'http_code' => 200,
            'response_headers' => ['Content-Type: application/pdf'],
        ]));

        $renderer = new DocRaptorPdfRenderer($mockClient, 'fake-key', true, new NullLogger());

        $bytes = $renderer->render('<html><body>Hi</body></html>');

        self::assertSame($fakePdf, $bytes);
    }

    public function testSendsExpectedJsonPayload(): void
    {
        $capturedBody = null;
        $capturedHeaders = null;
        $mockClient = new MockHttpClient(function (string $method, string $url, array $opts) use (&$capturedBody, &$capturedHeaders): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringEndsWith('docraptor.com/docs', $url);
            $capturedBody = $opts['body'] ?? null;
            $capturedHeaders = $opts['headers'] ?? [];

            return new MockResponse('%PDF-bytes', ['http_code' => 200]);
        });

        $renderer = new DocRaptorPdfRenderer($mockClient, 'key-123', testMode: true, logger: new NullLogger());
        $renderer->render('<p>X</p>', new PdfOptions(
            format: PdfFormat::A4,
            orientation: PdfOrientation::Portrait,
            marginTop: '18mm',
            marginRight: '16mm',
            marginBottom: '20mm',
            marginLeft: '16mm',
        ));

        self::assertIsString($capturedBody);
        $payload = json_decode($capturedBody, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('pdf', $payload['type']);
        self::assertTrue($payload['test']);
        self::assertSame('<p>X</p>', $payload['document_content']);
        self::assertSame('A4', $payload['prince_options']['page_size']);
        self::assertSame('print', $payload['prince_options']['media']);
        self::assertSame('18mm', $payload['prince_options']['margin_top']);
        self::assertSame('20mm', $payload['prince_options']['margin_bottom']);

        // Basic Auth header carries the API key as the username, empty password.
        $authHeader = self::findHeader($capturedHeaders, 'Authorization');
        self::assertNotNull($authHeader);
        self::assertStringStartsWith('Basic ', $authHeader);
        self::assertSame('key-123:', base64_decode(substr($authHeader, 6), true));
    }

    public function testOmitsNullMarginsFromPayload(): void
    {
        $captured = null;
        $mockClient = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = $opts['body'];

            return new MockResponse('%PDF-bytes', ['http_code' => 200]);
        });

        $renderer = new DocRaptorPdfRenderer($mockClient, 'key', true, new NullLogger());
        $renderer->render('<p>X</p>', PdfOptions::default());

        $payload = json_decode($captured, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('margin_top', $payload['prince_options']);
        self::assertArrayNotHasKey('margin_bottom', $payload['prince_options']);
    }

    public function testLandscapeInjectsAtPageCssPrelude(): void
    {
        $captured = null;
        $mockClient = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = $opts['body'];

            return new MockResponse('%PDF-bytes', ['http_code' => 200]);
        });

        $renderer = new DocRaptorPdfRenderer($mockClient, 'key', true, new NullLogger());
        $renderer->render('<p>X</p>', new PdfOptions(
            format: PdfFormat::A4,
            orientation: PdfOrientation::Landscape,
        ));

        $payload = json_decode($captured, true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('@page { size: A4 landscape;', $payload['document_content']);
        // Original markup is preserved after the prelude.
        self::assertStringEndsWith('<p>X</p>', $payload['document_content']);
    }

    public function testThrowsPdfRenderExceptionOnNon2xx(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('{"error":"invalid html"}', [
            'http_code' => 422,
            'response_headers' => ['Content-Type: application/json'],
        ]));

        $renderer = new DocRaptorPdfRenderer($mockClient, 'key', true, new NullLogger());

        $this->expectException(PdfRenderException::class);
        $this->expectExceptionMessage('HTTP 422');

        $renderer->render('<p>X</p>');
    }

    public function testThrowsWhenApiKeyIsEmpty(): void
    {
        $renderer = new DocRaptorPdfRenderer(new MockHttpClient(), '', true, new NullLogger());

        $this->expectException(PdfRenderException::class);
        $this->expectExceptionMessage('DOC_RAPTOR_KEY is not configured.');

        $renderer->render('<p>X</p>');
    }

    /**
     * @param array<int|string, mixed> $headers
     */
    private static function findHeader(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (\is_int($key) && \is_string($value)) {
                [$headerName, $headerValue] = array_pad(explode(': ', $value, 2), 2, '');
                if (strtolower($headerName) === $lower) {
                    return $headerValue;
                }
            } elseif (\is_string($key) && strtolower($key) === $lower) {
                return \is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        return null;
    }
}
