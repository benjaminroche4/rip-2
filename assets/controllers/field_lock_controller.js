/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Read-mode lock for autosaved text fields: a filled field renders as plain
 * text with a pencil button, the pencil swaps in the editor and focuses it
 * (caret at the end). The autosave POST reloads the page, which re-renders
 * the field locked again — leaving the field locks it back on its own.
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
}
