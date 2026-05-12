<?php

declare(strict_types=1);

namespace App\Admin\Service;

use App\Admin\Entity\DocumentRequest;
use App\Shared\Pdf\PdfFormat;
use App\Shared\Pdf\PdfOptions;
use App\Shared\Pdf\PdfOrientation;
use App\Shared\Pdf\PdfRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Renders a DocumentRequest into a downloadable PDF. The Twig template is
 * locale-aware (driven by the request's language enum), so the same call
 * produces either a French or English document.
 *
 * Layout/styling decisions live entirely in the template — this class
 * only assembles inputs (HTML + a few page-level options) and hands the
 * job off to the generic {@see PdfRenderer}. Swapping the backend later
 * (PDFShift, Browserless, a self-hosted Gotenberg) is a one-line change
 * in services config; the contract stays the same.
 */
final readonly class DocumentRequestPdfRenderer
{
    public function __construct(
        private Environment $twig,
        private TranslatorInterface $translator,
        private PdfRenderer $pdfRenderer,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function render(DocumentRequest $request): string
    {
        return $this->pdfRenderer->render($this->renderHtml($request), $this->options());
    }

    /**
     * Renders the source HTML without sending it to the PDF backend. Used
     * by tests that inspect the rendered markup; can also be reused if
     * the team ever needs to preview the template in the browser without
     * touching the DocRaptor quota.
     */
    public function renderHtml(DocumentRequest $request): string
    {
        $locale = $request->getLanguage()->value;

        return $this->twig->render('pdf/document_request.html.twig', [
            'request' => $request,
            'locale' => $locale,
            'translator' => $this->translator,
            'logoDataUri' => $this->logoDataUri(),
        ]);
    }

    public function filename(DocumentRequest $request): string
    {
        $date = $request->getCreatedAt()?->format('Y-m-d') ?? date('Y-m-d');

        return sprintf('demande-documents-%s-%d.pdf', $date, $request->getId() ?? 0);
    }

    /**
     * A4 portrait with generous margins so the bilingual content breathes.
     * Pulled into a dedicated method so individual tweaks stay close to
     * the use case and the renderer stays purely about wiring.
     */
    private function options(): PdfOptions
    {
        return new PdfOptions(
            format: PdfFormat::A4,
            orientation: PdfOrientation::Portrait,
            marginTop: '18mm',
            marginRight: '16mm',
            marginBottom: '20mm',
            marginLeft: '16mm',
        );
    }

    /**
     * Inline the brand wordmark as a base64 data URI so the renderer can
     * embed it without depending on a public asset URL — keeps the PDF
     * usable when downloaded and viewed offline.
     */
    private function logoDataUri(): string
    {
        $path = $this->projectDir.'/public/medias/logos/logo_red.svg';
        $svg = @file_get_contents($path);

        if (false === $svg) {
            return '';
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
