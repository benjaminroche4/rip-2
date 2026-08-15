/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Copies a value to the clipboard and confirms through the global toast
 * stack (`toast:show`), never by swapping the button label: a button whose
 * text changes underneath the cursor reads as a different control.
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
        window.dispatchEvent(new CustomEvent('toast:show', {
            detail: { message: this.copiedLabelValue },
        }));
    }
}
