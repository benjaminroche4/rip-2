/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

// Smooth-scrolls the element into view on connect. Used after a Turbo Stream
// replaces the contact form with the success confirmation: the user clicked
// the submit button at the bottom of the form, so without this their viewport
// stays at the bottom and the success message is invisible above the fold.
export default class extends Controller {
    static values = {
        block: { type: String, default: 'start' },
    };

    connect() {
        // Defer one frame so the layout settles after the Turbo Stream replace.
        requestAnimationFrame(() => {
            this.element.scrollIntoView({
                behavior: 'smooth',
                block: this.blockValue,
            });
        });
    }
}
