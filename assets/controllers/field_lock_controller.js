/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Read-mode lock for autosaved text fields: a filled field renders as plain
 * text with a pencil button, the pencil swaps in the editor and focuses it
 * (caret at the end). Leaving the field locks it back: a modified value
 * autosaves (change fires before blur, the POST reloads the page locked),
 * an untouched value just swaps the plain text back in (relock on blur).
 * An empty field renders directly in edit mode (no display target).
 */
export default class extends Controller {
    static targets = ['display', 'editor'];

    unlock() {
        if (this.hasDisplayTarget) {
            this.displayTarget.classList.add('hidden');
        }
        this.editorTarget.classList.remove('hidden');
        this.editorTarget.focus();
        const end = this.editorTarget.value.length;
        this.editorTarget.setSelectionRange(end, end);
    }

    relock() {
        if (!this.hasDisplayTarget) {
            return;
        }
        this.editorTarget.classList.add('hidden');
        this.displayTarget.classList.remove('hidden');
    }
}
