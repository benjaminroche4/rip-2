/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Styled confirmation for destructive form submissions (replaces the native
 * window.confirm): a form with data-action="submit->confirm-dialog#intercept"
 * and data-confirm-message="..." opens the shared modal instead of
 * submitting; confirming re-submits the same form for real. Without JS the
 * form submits directly, which stays acceptable for these low-stakes
 * deletions. The overlay carries [data-entered] while open so the CSS
 * entrance/exit transitions play around the hidden toggle.
 */
export default class extends Controller {
    static targets = ['overlay', 'message'];

    #pendingForm = null;
    #confirmed = false;
    #frame = null;
    #timer = null;

    disconnect() {
        cancelAnimationFrame(this.#frame);
        clearTimeout(this.#timer);
    }

    intercept(event) {
        if (this.#confirmed) {
            this.#confirmed = false;

            return;
        }
        if (!this.hasOverlayTarget) {
            return;
        }
        event.preventDefault();
        this.#pendingForm = event.target;
        this.messageTarget.textContent = event.target.dataset.confirmMessage ?? '';
        clearTimeout(this.#timer);
        this.overlayTarget.hidden = false;
        this.#frame = requestAnimationFrame(() => {
            this.#frame = requestAnimationFrame(() => {
                this.overlayTarget.dataset.entered = '';
            });
        });
    }

    confirm() {
        this.#close();
        if (this.#pendingForm) {
            this.#confirmed = true;
            this.#pendingForm.requestSubmit();
            this.#pendingForm = null;
        }
    }

    cancel() {
        if (!this.hasOverlayTarget || this.overlayTarget.hidden) {
            return;
        }
        this.#close();
        this.#pendingForm = null;
    }

    #close() {
        cancelAnimationFrame(this.#frame);
        delete this.overlayTarget.dataset.entered;
        this.#timer = setTimeout(() => {
            this.overlayTarget.hidden = true;
        }, 150);
    }
}
