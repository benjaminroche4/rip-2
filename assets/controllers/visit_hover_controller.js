/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Two-way hover sync between a visit row and its pin on the day map:
 * hovering the row dims every other pin (visit-row:hover, consumed by
 * visit-map), and hovering a pin highlights the matching row with a ring
 * (visit-pin:hover, emitted by visit-map). All wiring goes through
 * data-action, nothing to clean up manually.
 */
export default class extends Controller {
    static values = { id: Number };

    enter() {
        window.dispatchEvent(new CustomEvent('visit-row:hover', { detail: { id: this.idValue } }));
    }

    leave() {
        window.dispatchEvent(new CustomEvent('visit-row:leave'));
    }

    pinEnter(event) {
        if (event.detail?.id === this.idValue) {
            this.element.classList.add('ring-2', 'ring-primary/40');
        }
    }

    pinLeave() {
        this.element.classList.remove('ring-2', 'ring-primary/40');
    }

    /** Clic sur le pin : la rangée défile en vue et clignote brièvement. */
    pinSelect(event) {
        if (event.detail?.id !== this.idValue) {
            return;
        }
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.element.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
        this.element.classList.add('ring-2', 'ring-primary/40');
        clearTimeout(this.flashTimer);
        this.flashTimer = setTimeout(() => this.pinLeave(), 1200);
    }

    disconnect() {
        clearTimeout(this.flashTimer);
    }
}
