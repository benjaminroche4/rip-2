/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Drops a real loading state on a "Download PDF" link. The default <a href>
 * navigation can't be observed while the server renders the PDF, so we
 * fetch() the URL ourselves, show a spinner on the button, then trigger
 * the file download via a Blob URL once we have the bytes.
 *
 * Wiring:
 *   <a href="{{ pdfUrl }}"
 *      data-controller="pdf-download"
 *      data-pdf-download-url-value="{{ pdfUrl }}"
 *      data-action="click->pdf-download#download">
 *     <span data-pdf-download-target="label">Download</span>
 *     <span data-pdf-download-target="spinner" class="hidden">…</span>
 *   </a>
 *
 * The href is kept for accessibility (right-click → save as, screen-reader
 * link semantics, JS-disabled fallback) — we just override click().
 */
export default class extends Controller {
    static values = {
        url: String,
        loadingClass: { type: String, default: 'pointer-events-none opacity-60' },
    };
    static targets = ['label', 'spinner'];

    async download(event) {
        event.preventDefault();

        if (!this.urlValue || this.isLoading) {
            return;
        }

        this.setLoading(true);

        try {
            const response = await fetch(this.urlValue, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error(`PDF download failed: HTTP ${response.status}`);
            }

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);

            // Preserve the server-side filename from Content-Disposition
            // so the saved file keeps the "demande-documents-…pdf" shape.
            let filename = '';
            const disposition = response.headers.get('Content-Disposition') || '';
            const match = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
            if (match) {
                filename = decodeURIComponent(match[1]);
            }

            const link = document.createElement('a');
            link.href = objectUrl;
            if (filename) {
                link.download = filename;
            } else {
                link.setAttribute('download', '');
            }
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            // Defer revocation slightly so the browser has time to start
            // the download — immediate revoke is racy on Firefox/Safari.
            setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
        } catch (error) {
            console.error(error);
            // Fall back to native navigation so the user still gets the file
            // even when fetch() fails (CORS, network blip, etc.).
            window.location.href = this.urlValue;
        } finally {
            this.setLoading(false);
        }
    }

    setLoading(loading) {
        this.isLoading = loading;
        this.element.setAttribute('aria-busy', loading ? 'true' : 'false');

        for (const cls of this.loadingClassValue.split(' ').filter(Boolean)) {
            this.element.classList.toggle(cls, loading);
        }

        if (this.hasLabelTarget) {
            this.labelTarget.classList.toggle('invisible', loading);
        }
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle('hidden', !loading);
        }
    }
}
