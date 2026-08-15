/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Loading feedback on a native download link. The navigation is NOT
 * intercepted: the browser streams the file itself and honours the server
 * Content-Disposition name — we only show a spinner while the server
 * prepares the payload, then restore after a grace delay (the download
 * start is not observable from JS on a plain link).
 *
 * Targets: label (hidden while loading), spinner (shown while loading).
 */
export default class extends Controller {
    static targets = ['label', 'spinner'];
    static values = {
        delay: { type: Number, default: 4000 },
    };

    #timer = null;

    start() {
        if (this.#timer) {
            return;
        }
        this.#toggle(true);
        this.#timer = setTimeout(() => {
            this.#timer = null;
            this.#toggle(false);
        }, this.delayValue);
    }

    disconnect() {
        clearTimeout(this.#timer);
        this.#timer = null;
    }

    #toggle(loading) {
        this.element.setAttribute('aria-busy', loading ? 'true' : 'false');
        this.element.classList.toggle('opacity-60', loading);
        if (this.hasLabelTarget) {
            this.labelTarget.classList.toggle('invisible', loading);
        }
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle('hidden', !loading);
        }
    }
}
