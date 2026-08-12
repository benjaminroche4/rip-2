<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Shared\Pdf\PdfOptions;
use App\Shared\Pdf\PdfRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * One-page branded PDF of the freshly generated 2FA recovery codes: the
 * only moment the plain codes exist, so the download happens from the
 * same one-time display window as the on-screen list.
 */
final readonly class RecoveryCodesPdfRenderer
{
    public function __construct(
        private Environment $twig,
        private PdfRenderer $pdfRenderer,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * @param list<string> $codes
     */
    public function render(array $codes, string $email): string
    {
        return $this->pdfRenderer->render($this->renderHtml($codes, $email), PdfOptions::default());
    }

    /**
     * Source HTML without hitting the PDF backend — used by tests.
     *
     * @param list<string> $codes
     */
    public function renderHtml(array $codes, string $email): string
    {
        return $this->twig->render('pdf/recovery_codes.html.twig', [
            'codes' => $codes,
            'email' => $email,
            'generatedAt' => new \DateTimeImmutable(),
            'logoDataUri' => $this->logoDataUri(),
        ]);
    }

    /**
     * Inline the brand wordmark as a base64 data URI so the renderer can
     * embed it without depending on a public asset URL.
     */
    private function logoDataUri(): string
    {
        $svg = @file_get_contents($this->projectDir.'/public/medias/logos/logo_red.svg');

        return false !== $svg ? 'data:image/svg+xml;base64,'.base64_encode($svg) : '';
    }
}
