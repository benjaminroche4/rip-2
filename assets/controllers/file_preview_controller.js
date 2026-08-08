/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Fullscreen preview of a deposited file, marketplace-lightbox style:
 * intercepts the click on a file link and shows the document centered on a
 * dark overlay — a native <img> for images, an <iframe> for PDFs. The link
 * keeps its href as a no-JS fallback and feeds the "open in new tab"
 * action. Trigger links carry data-file-preview-name and
 * data-file-preview-type (mime type).
 */
export default class extends Controller {
    static targets = ['overlay', 'frame', 'image', 'title', 'newTab'];

    open(event) {
        if (!this.hasOverlayTarget) {
            return;
        }
        event.preventDefault();
        const link = event.currentTarget;
        const isImage = (link.dataset.filePreviewType ?? '').startsWith('image/');

        this.imageTarget.hidden = !isImage;
        this.frameTarget.hidden = isImage;
        if (isImage) {
            this.imageTarget.src = link.href;
            this.frameTarget.src = 'about:blank';
        } else {
            this.frameTarget.src = link.href;
            this.imageTarget.removeAttribute('src');
        }

        this.newTabTarget.href = link.href;
        this.titleTarget.textContent = link.dataset.filePreviewName ?? link.textContent.trim();
        this.overlayTarget.hidden = false;
    }

    /** Backdrop click closes; clicks on the document itself do not. */
    closeOnBackdrop(event) {
        if (event.target === event.currentTarget) {
            this.close();
        }
    }

    close() {
        if (!this.hasOverlayTarget || this.overlayTarget.hidden) {
            return;
        }
        this.overlayTarget.hidden = true;
        // Stop any renderer left running in the hidden viewers.
        this.frameTarget.src = 'about:blank';
        this.imageTarget.removeAttribute('src');
    }
}
