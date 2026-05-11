<?php

declare(strict_types=1);

namespace App\Admin\Service;

use App\Admin\Entity\DocumentRequest;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Renders a DocumentRequest into a downloadable PDF. The Twig template is
 * locale-aware (driven by the request's language enum), so the same render
 * call produces either a French or English PDF.
 *
 * Dompdf is configured for o2switch compatibility — pure PHP, no shell,
 * isRemoteEnabled disabled (defense-in-depth against injected <img> tags).
 */
final readonly class DocumentRequestPdfRenderer
{
    public function __construct(
        private Environment $twig,
        private TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function render(DocumentRequest $request): string
    {
        $locale = $request->getLanguage()->value;

        $html = $this->twig->render('pdf/document_request.html.twig', [
            'request' => $request,
            'locale' => $locale,
            'translator' => $this->translator,
            'logoDataUri' => $this->logoDataUri(),
        ]);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function filename(DocumentRequest $request): string
    {
        $date = $request->getCreatedAt()?->format('Y-m-d') ?? date('Y-m-d');

        return sprintf('demande-documents-%s-%d.pdf', $date, $request->getId() ?? 0);
    }

    /**
     * Inline the brand wordmark as a base64 data URI so Dompdf can render it
     * without enabling remote fetching or widening its chroot.
     */
    private function logoDataUri(): string
    {
        $path = $this->projectDir.'/public/medias/logos/logo_red.svg';
        $svg = @file_get_contents($path);

        if ($svg === false) {
            return '';
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
