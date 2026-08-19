/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Library mode of the visit photo card: the gallery is read-only by
 * default, the discreet pencil switches the card to manage mode. The
 * upload and delete forms always stay in the DOM (crawler-driven tests,
 * no-JS fallback); only a class on the card reveals them via CSS.
 */
export default class extends Controller {
    static targets = ['toggle'];
    static classes = ['managing'];

    toggle() {
        const active = this.element.classList.toggle(this.managingClass);
        this.toggleTarget.setAttribute('aria-pressed', active ? 'true' : 'false');
    }

    disconnect() {
        // A Turbo cache restore must never resurrect the manage mode.
        this.element.classList.remove(this.managingClass);
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-pressed', 'false');
        }
    }
}
