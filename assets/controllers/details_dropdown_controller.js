/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Closes a native <details> dropdown when the user clicks outside of it or
 * presses Escape. Attach on the <details> element:
 *
 *   <details data-controller="details-dropdown"
 *            data-action="click@window->details-dropdown#closeOnOutside keydown.esc@window->details-dropdown#close">
 */
export default class extends Controller {
    closeOnOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    close() {
        this.element.removeAttribute('open');
    }
}
