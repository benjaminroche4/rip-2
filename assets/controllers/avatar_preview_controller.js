/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Live preview of a picked profile photo: mirrors the chosen file into
 * every preview <img>, hides the placeholders and shows the file name.
 * The real <input type="file"> stays the single source of truth.
 */
export default class extends Controller {
    static targets = ['input', 'preview', 'placeholder', 'filename'];

    #objectUrl = null;

    changed() {
        const file = this.inputTarget.files?.[0] ?? null;
        if (!file) {
            return;
        }
        this.#revoke();
        this.#objectUrl = URL.createObjectURL(file);
        for (const preview of this.previewTargets) {
            preview.src = this.#objectUrl;
            preview.hidden = false;
        }
        for (const placeholder of this.placeholderTargets) {
            placeholder.hidden = true;
        }
        for (const label of this.filenameTargets) {
            label.textContent = file.name;
        }
    }

    disconnect() {
        this.#revoke();
    }

    #revoke() {
        if (this.#objectUrl) {
            URL.revokeObjectURL(this.#objectUrl);
            this.#objectUrl = null;
        }
    }
}
