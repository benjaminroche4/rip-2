/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Mirrors the first/last name inputs into the card title as the admin
 * types, so the header follows each keystroke without a server round-trip.
 * Falls back to the title's data-default (saved name or "New person") when
 * both inputs are empty.
 */
export default class extends Controller {
    static targets = ['first', 'last', 'output'];

    refresh() {
        const first = this.hasFirstTarget ? this.firstTarget.value.trim() : '';
        const last = this.hasLastTarget ? this.lastTarget.value.trim() : '';
        const name = `${first} ${last}`.trim();
        for (const output of this.outputTargets) {
            output.textContent = name || (output.dataset.default ?? '');
        }
    }
}
