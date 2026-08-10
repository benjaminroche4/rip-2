/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Triggers a file download in response to a custom DOM event whose detail
 * carries a `url`. Used by Admin:DocumentRequestForm to start the PDF
 * download from a LiveAction without navigating the page.
 *
 * The file is fetched as a blob so the button's loader can stay visible
 * until the download has actually completed (the PDF render takes a few
 * seconds) — the LiveAction's own data-loading only covers the save phase.
 *
 * Wiring:
 *   <form data-controller="download-trigger"
 *         data-action="document-request:download@window->download-trigger#download">
 *
 * The LiveAction calls dispatchBrowserEvent('document-request:download', {url: '...'})
 * on the form's root, which bubbles to window where this controller catches it.
 */
export default class extends Controller {
    static targets = ['button', 'label', 'spinner'];

    async download(event) {
        const url = event?.detail?.url;
        if (!url) return;

        this.#setLoading(true);
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                throw new Error(`PDF download failed with status ${response.status}`);
            }
            const blob = await response.blob();

            const disposition = response.headers.get('content-disposition') ?? '';
            const match = disposition.match(/filename\*?=(?:UTF-8''|")?([^";]+)/i);
            const filename = match ? decodeURIComponent(match[1].replace(/"/g, '').trim()) : 'document-request.pdf';

            const objectUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = objectUrl;
            a.setAttribute('download', filename);
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(objectUrl);
        } finally {
            this.#setLoading(false);
        }
    }

    #setLoading(loading) {
        if (this.hasButtonTarget) {
            this.buttonTarget.toggleAttribute('disabled', loading);
        }
        if (this.hasLabelTarget) {
            this.labelTarget.classList.toggle('invisible', loading);
        }
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle('hidden', !loading);
        }
    }
}
