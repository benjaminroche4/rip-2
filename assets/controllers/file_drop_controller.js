/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Generic drop zone feeding an existing <input type="file">: dropping a
 * file on the controller element assigns it to the input and fires its
 * `change` event, so whatever is wired on the input (auto-submit, XHR
 * upload) runs unchanged. Files not matching the input's `accept` list are
 * ignored. dragenter/dragleave bubble from children, hence the depth
 * counter guarding the highlight.
 */
export default class extends Controller {
    static targets = ['input'];
    static classes = ['drag'];

    #dragDepth = 0;

    dragOver(event) {
        if (!this.#draggingFiles(event)) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    }

    dragEnter(event) {
        if (!this.#draggingFiles(event)) {
            return;
        }
        event.preventDefault();
        this.#dragDepth += 1;
        this.element.classList.add(...this.dragClasses);
    }

    dragLeave() {
        this.#dragDepth = Math.max(0, this.#dragDepth - 1);
        if (0 === this.#dragDepth) {
            this.element.classList.remove(...this.dragClasses);
        }
    }

    drop(event) {
        if (!this.#draggingFiles(event)) {
            return;
        }
        event.preventDefault();
        this.#dragDepth = 0;
        this.element.classList.remove(...this.dragClasses);

        const file = [...event.dataTransfer.files].find((candidate) => this.#accepted(candidate));
        if (!file || !this.hasInputTarget) {
            return;
        }
        const transfer = new DataTransfer();
        transfer.items.add(file);
        this.inputTarget.files = transfer.files;
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /* A file dropped next to the zone must not navigate the browser away. */
    guardWindow(event) {
        if (this.#draggingFiles(event)) {
            event.preventDefault();
        }
    }

    #accepted(file) {
        const accept = (this.inputTarget.accept ?? '').split(',').map((type) => type.trim()).filter(Boolean);

        return 0 === accept.length || accept.includes(file.type);
    }

    #draggingFiles(event) {
        return [...(event.dataTransfer?.types ?? [])].includes('Files');
    }
}
