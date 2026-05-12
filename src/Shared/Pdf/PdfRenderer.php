<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

/**
 * Renders an HTML document into a PDF byte stream. The single method on
 * this interface is intentionally minimal so any future provider
 * (PDFShift, Browserless, a Gotenberg instance, etc.) can be swapped
 * in by registering its implementation behind this interface — no
 * change required at the call site.
 *
 * Callers supply already-rendered HTML (templates resolved, locale
 * applied, data injected). Backends are not allowed to mutate the
 * HTML beyond what the PDF rendering requires.
 */
interface PdfRenderer
{
    /**
     * @throws PdfRenderException when the backend rejects the HTML, the
     *                            network call fails, or the provider
     *                            returns a non-2xx response.
     */
    public function render(string $html, ?PdfOptions $options = null): string;
}
