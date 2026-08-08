/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Copies a value to the clipboard and briefly swaps the button label to a
 * "copied" confirmation. The timer is cleared on disconnect so a morphed
 * card never leaves a stale callback behind.
 */
export default class extends Controller {
    static targets = ['label'];
    static values = {
        text: String,
        copiedLabel: String,
    };

    async copy() {
        try {
            await navigator.clipboard.writeText(this.textValue);
        } catch {
            return;
        }
        if (!this.hasLabelTarget) {
            return;
        }
        const original = this.labelTarget.textContent;
        this.labelTarget.textContent = this.copiedLabelValue;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            this.labelTarget.textContent = original;
        }, 1500);
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
