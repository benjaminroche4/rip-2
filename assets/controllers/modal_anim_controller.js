/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Entrance/exit micro-transition for confirmation modals. The root gains
 * [data-entered] one frame after mount so the CSS transitions written with
 * group-data-entered/modal variants play in; close actions call out() to
 * drop the attribute, and the reverse transition plays while the live
 * round-trip removes the node from the DOM.
 */
export default class extends Controller {
    connect() {
        this.frame = requestAnimationFrame(() => {
            this.frame = requestAnimationFrame(() => {
                this.element.dataset.entered = '';
            });
        });
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
    }

    out() {
        delete this.element.dataset.entered;
    }
}
