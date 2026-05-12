<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Monolog\Attribute\WithMonologChannel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Renders HTML to PDF through DocRaptor (HTTP → Chrome/Prince → PDF).
 *
 * Why DocRaptor instead of a local renderer:
 *  - o2switch mutualisé blocks the binaries needed for Chrome headless
 *    or wkhtmltopdf, and PHP-only renderers (Dompdf, mPDF) only cover
 *    a small CSS subset. DocRaptor lets us write modern HTML + Tailwind
 *    in the template and have it look the same in the PDF as in the
 *    admin UI.
 *
 * Operational notes:
 *  - `DOC_RAPTOR_TEST_MODE=true` switches to the watermarked test API
 *    (free, unlimited). Keep it `true` in dev/CI, flip to `false` in
 *    `.env.local` once the production key is in place.
 *  - Errors are wrapped in {@see PdfRenderException} so callers see a
 *    single exception type and can degrade (logging, fallback, retry)
 *    without depending on Symfony HTTP client internals.
 */
#[WithMonologChannel('pdf')]
final readonly class DocRaptorPdfRenderer implements PdfRenderer
{
    private const ENDPOINT = 'https://docraptor.com/docs';
    private const DEFAULT_TIMEOUT_SECONDS = 60;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(DOC_RAPTOR_KEY)%')]
        private string $apiKey,
        #[Autowire('%env(bool:DOC_RAPTOR_TEST_MODE)%')]
        private bool $testMode = true,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function render(string $html, ?PdfOptions $options = null): string
    {
        if ('' === $this->apiKey) {
            throw new PdfRenderException('DOC_RAPTOR_KEY is not configured.');
        }

        $options ??= PdfOptions::default();

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'auth_basic' => [$this->apiKey, ''],
                'json' => $this->buildPayload($html, $options),
                'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
                // We expect raw PDF bytes; the Accept header signals it
                // to DocRaptor and prevents Symfony's HttpClient from
                // trying to auto-decode the body as JSON.
                'headers' => ['Accept' => 'application/pdf'],
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);

            if ($status < 200 || $status >= 300) {
                $this->logger->error('DocRaptor returned a non-2xx status', [
                    'status' => $status,
                    'body_excerpt' => mb_substr($body, 0, 500),
                ]);
                throw new PdfRenderException(sprintf('DocRaptor responded with HTTP %d.', $status));
            }

            return $body;
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('DocRaptor HTTP transport failure', ['exception_class' => $e::class]);
            throw new PdfRenderException('DocRaptor render failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $html, PdfOptions $options): array
    {
        $princeOptions = [
            'media' => 'print',
            'page_size' => $options->format->value,
        ];

        // Margins map 1-to-1 to Prince's CSS-like overrides — keep `null`
        // out of the payload so DocRaptor falls back to its defaults.
        $margins = array_filter([
            'margin_top' => $options->marginTop,
            'margin_right' => $options->marginRight,
            'margin_bottom' => $options->marginBottom,
            'margin_left' => $options->marginLeft,
        ], static fn (?string $v): bool => null !== $v);

        if (PdfOrientation::Landscape === $options->orientation) {
            // Prince doesn't expose a separate orientation flag — rotate
            // the page via @page CSS. We inject a tiny prelude so
            // callers don't have to remember.
            $html = '<style>@page { size: '.$options->format->value.' landscape; }</style>'.$html;
        }

        return [
            'type' => 'pdf',
            'test' => $this->testMode,
            'document_content' => $html,
            'prince_options' => array_merge($princeOptions, $margins),
        ];
    }
}
