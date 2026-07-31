<?php

declare(strict_types=1);

namespace App\Admin\Service;

use App\Contact\Domain\ContactListItem;
use App\Shared\Pdf\PdfFormat;
use App\Shared\Pdf\PdfOptions;
use App\Shared\Pdf\PdfOrientation;
use App\Shared\Pdf\PdfRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Renders a contact submission into a downloadable PDF, using the same
 * visual language as the document-request export (nested gray→white
 * cards mirroring the transactional emails). The template is bilingual,
 * driven by the submission's language.
 *
 * Meant to be forwarded to partners: every stored field is included
 * except internal-only data (IP, follow-up notes thread, lead rating and
 * qualification note).
 */
final readonly class ContactPdfRenderer
{
    public function __construct(
        private Environment $twig,
        private TranslatorInterface $translator,
        private PdfRenderer $pdfRenderer,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function render(ContactListItem $contact): string
    {
        return $this->pdfRenderer->render($this->renderHtml($contact), $this->options());
    }

    /**
     * Renders the source HTML without hitting the PDF backend — used by
     * tests and reusable for an in-browser preview.
     */
    public function renderHtml(ContactListItem $contact): string
    {
        $locale = \in_array($contact->lang, ['fr', 'en'], true) ? $contact->lang : 'fr';

        return $this->twig->render('pdf/contact.html.twig', [
            'contact' => $contact,
            'locale' => $locale,
            'helpTypeLabel' => $this->translator->trans($contact->helpType, locale: $locale),
            'offerLabel' => null !== $contact->offer
                ? \sprintf(
                    '%s (%s)',
                    $this->translator->trans('contact.contactForm.offer.'.$contact->offer.'.title', locale: $locale),
                    $this->translator->trans('contact.contactForm.offer.'.$contact->offer.'.price', locale: $locale),
                )
                : null,
            'logoDataUri' => $this->logoDataUri(),
        ]);
    }

    public function filename(ContactListItem $contact): string
    {
        return sprintf('demande-contact-%s.pdf', $contact->reference);
    }

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
     * embed it without depending on a public asset URL.
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
