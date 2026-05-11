/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Triggers a file download in response to a custom DOM event whose detail
 * carries a `url`. Used by Admin:DocumentRequestForm to start the PDF
 * download from a LiveAction without navigating the page.
 *
 * Wiring:
 *   <form data-controller="download-trigger"
 *         data-action="document-request:download@window->download-trigger#download">
 *
 * The LiveAction calls dispatchBrowserEvent('document-request:download', {url: '...'})
 * on the form's root, which bubbles to window where this controller catches it.
 */
export default class extends Controller {
    download(event) {
        const url = event?.detail?.url;
        if (!url) return;

        const a = document.createElement('a');
        a.href = url;
        // Hint to the browser this is a download; the server also sends
        // Content-Disposition: attachment, so the actual filename comes
        // from there.
        a.setAttribute('download', '');
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
}
