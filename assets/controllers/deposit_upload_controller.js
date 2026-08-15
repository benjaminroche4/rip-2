/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

/**
 * Uploads deposit-page piece files through XHR as soon as they are picked
 * (file input or drag & drop on the whole card), so a real percentage can
 * be shown (Turbo's fetch submission exposes no upload progress and a 8 MB
 * scan on mobile looks frozen otherwise).
 *
 * Several files are sent sequentially; the server's Turbo Stream response
 * replaces the #deposit-documents region, which would destroy this
 * controller (and the queue) mid-run, so only the LAST response is
 * rendered, once the whole queue is done. The batch is validated upfront
 * (PDF or photo, 10 MB per file, matching the server rules): one bad file
 * rejects the batch with an instant, precise message instead of a wasted
 * upload. On network failure the form falls back to a native submit.
 */
export default class extends Controller {
    static targets = ['form', 'input', 'label', 'status', 'bar', 'fill', 'cancel', 'skeleton'];
    static classes = ['drag'];
    static values = {
        uploadingLabel: String,
        uploadingManyLabel: String,
        errorLabel: String,
        dropErrorLabel: String,
        tooLargeLabel: String,
        uploadedAnnounce: String,
    };

    /* Mirrors the server-side Assert\File(maxSize: '10M') — suffix M is
       1000-based in Symfony. */
    static MAX_BYTES = 10_000_000;

    /* Mirrors the server-side Assert\File(mimeTypes) list exactly. */
    static ACCEPTED_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    #xhr = null;
    #queue = [];
    #queueTotal = 0;
    #lastStream = null;
    #aborted = false;
    #dragDepth = 0;

    /* Drag & drop: the whole piece card is a drop zone. dragenter/dragleave
       bubble from every child, so a depth counter avoids flicker. */
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
        this.#enqueue([...event.dataTransfer.files]);
    }

    /* Window-level guard: a file dropped outside a card must not navigate
       the browser to the PDF. Card drops preventDefault first, so this only
       neutralizes strays. */
    guardWindow(event) {
        if (this.#draggingFiles(event)) {
            event.preventDefault();
        }
    }

    /* Two file inputs share the target: the regular picker and the
       mobile-only camera capture. The triggering input is read from the
       change event; both are reset once the queue settles. */
    start(event) {
        this.#enqueue([...(event.target.files ?? [])]);
    }

    cancel() {
        this.#aborted = true;
        this.#queue = [];
        this.#xhr?.abort();
        this.#xhr = null;
        // Files already uploaded before the cancel are real: reflect them.
        this.#settle(null);
    }

    disconnect() {
        this.#xhr?.abort();
        this.#xhr = null;
    }

    #enqueue(files) {
        if (this.#xhr || 0 === files.length) {
            return;
        }
        // Upfront batch validation, mirroring the server rules: instant
        // feedback, nothing uploaded when one file is off.
        for (const file of files) {
            // The hidden inputs' accept filters do not apply to drops.
            if (!this.constructor.ACCEPTED_TYPES.includes(file.type)) {
                this.#reject(this.dropErrorLabelValue);

                return;
            }
            if (file.size > this.constructor.MAX_BYTES) {
                this.#reject(this.tooLargeLabelValue);

                return;
            }
        }

        this.#queue = files;
        this.#queueTotal = files.length;
        this.#lastStream = null;
        this.#aborted = false;
        this.#begin();
        this.#sendNext();
    }

    #sendNext() {
        const file = this.#queue.shift();
        if (!file) {
            this.#settle(this.#aborted ? null : this.uploadedAnnounceValue);

            return;
        }

        const xhr = new XMLHttpRequest();
        this.#xhr = xhr;
        xhr.open('POST', this.formTarget.action);
        xhr.setRequestHeader('Accept', 'text/vnd.turbo-stream.html, text/html');
        xhr.upload.addEventListener('progress', (progress) => {
            if (progress.lengthComputable) {
                this.#progress(Math.round((progress.loaded / progress.total) * 100));
            }
        });
        xhr.addEventListener('load', () => {
            this.#xhr = null;
            if ((xhr.getResponseHeader('Content-Type') ?? '').includes('text/vnd.turbo-stream.html')) {
                this.#lastStream = xhr.responseText;
                this.#sendNext();

                return;
            }
            if (xhr.status >= 200 && xhr.status < 400) {
                // Redirect fallback (303 followed by the browser): a plain
                // GET on the final URL shows the fresh state.
                window.location.assign(xhr.responseURL || window.location.href);

                return;
            }
            // Error page from the infrastructure (413 post_max_size, 403,
            // 500): responseURL is the POST-only upload route, navigating
            // there would 405. Show the error inline and allow a retry.
            this.#queue = [];
            this.#settle(null);
            this.#reject(this.errorLabelValue);
        });
        // Network failure before the server processed anything: retry with a
        // plain native submit so the user is never stuck.
        xhr.addEventListener('error', () => {
            this.#xhr = null;
            this.formTarget.submit();
        });

        const payload = new FormData(this.formTarget);
        payload.set('file', file);
        this.#progress(0);
        xhr.send(payload);
    }

    /* End of queue (finished, cancelled or failed): render the last server
       state once, and voice it for screen readers via the live region that
       lives OUTSIDE the replaced markup (Turbo Stream swaps are silent). */
    #settle(announcement) {
        if (announcement) {
            const announcer = document.getElementById('deposit-announcer');
            if (announcer) {
                announcer.textContent = announcement;
            }
        }
        this.labelTargets.forEach((label) => label.classList.remove('pointer-events-none', 'opacity-60'));
        if (this.hasSkeletonTarget) {
            this.skeletonTarget.hidden = true;
        }
        if (this.hasBarTarget) {
            this.barTarget.hidden = true;
        }
        if (this.hasCancelTarget) {
            this.cancelTarget.hidden = true;
        }
        this.#resetInputs();
        if (this.#lastStream) {
            renderStreamMessage(this.#lastStream);
            this.#lastStream = null;
        }
    }

    #begin() {
        this.labelTargets.forEach((label) => label.classList.add('pointer-events-none', 'opacity-60'));
        if (this.hasSkeletonTarget) {
            this.skeletonTarget.hidden = false;
        }
        if (this.hasBarTarget) {
            this.barTarget.hidden = false;
        }
        if (this.hasCancelTarget) {
            this.cancelTarget.hidden = false;
        }
        this.#progress(0);
    }

    #reject(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
        }
        // Same file re-picked must fire `change` again.
        this.#resetInputs();
    }

    #resetInputs() {
        this.inputTargets.forEach((input) => {
            input.value = '';
        });
    }

    #progress(percent) {
        if (this.hasStatusTarget) {
            const index = this.#queueTotal - this.#queue.length;
            const label = this.#queueTotal > 1
                ? this.uploadingManyLabelValue.replace('%index%', `${index}`).replace('%count%', `${this.#queueTotal}`)
                : this.uploadingLabelValue;
            this.statusTarget.textContent = label.replace('%percent%', `${percent} %`);
        }
        if (this.hasFillTarget) {
            this.fillTarget.style.setProperty('--progress', `${percent}%`);
        }
    }

    #draggingFiles(event) {
        return [...(event.dataTransfer?.types ?? [])].includes('Files');
    }
}
