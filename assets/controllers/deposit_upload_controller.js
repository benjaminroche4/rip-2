/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

/**
 * Uploads a deposit-page piece through XHR as soon as a file is picked, so
 * a real percentage can be shown (Turbo's fetch submission exposes no
 * upload progress and a 8 MB scan on mobile looks frozen otherwise). The
 * server answers with a Turbo Stream that replaces the #deposit-documents
 * region in place; Stimulus reconnects the controllers on the fresh markup.
 * On network failure the form falls back to a native submit.
 */
export default class extends Controller {
    static targets = ['input', 'label', 'status', 'bar', 'fill'];
    static values = {
        uploadingLabel: String,
        errorLabel: String,
    };

    #xhr = null;

    start() {
        if (this.#xhr || 0 === (this.inputTarget.files?.length ?? 0)) {
            return;
        }

        const xhr = new XMLHttpRequest();
        this.#xhr = xhr;
        xhr.open('POST', this.element.action);
        xhr.setRequestHeader('Accept', 'text/vnd.turbo-stream.html, text/html');
        xhr.upload.addEventListener('progress', (progress) => {
            if (!progress.lengthComputable) {
                return;
            }
            this.#progress(Math.round((progress.loaded / progress.total) * 100));
        });
        xhr.addEventListener('load', () => {
            this.#xhr = null;
            if ((xhr.getResponseHeader('Content-Type') ?? '').includes('text/vnd.turbo-stream.html')) {
                renderStreamMessage(xhr.responseText);

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
            this.#fail();
        });
        // Network failure before the server processed anything: retry with a
        // plain native submit so the user is never stuck.
        xhr.addEventListener('error', () => {
            this.#xhr = null;
            this.element.submit();
        });

        this.#begin();
        xhr.send(new FormData(this.element));
    }

    disconnect() {
        this.#xhr?.abort();
        this.#xhr = null;
    }

    #begin() {
        this.labelTarget.classList.add('pointer-events-none', 'opacity-60');
        if (this.hasBarTarget) {
            this.barTarget.hidden = false;
        }
        this.#progress(0);
    }

    #fail() {
        this.labelTarget.classList.remove('pointer-events-none', 'opacity-60');
        if (this.hasBarTarget) {
            this.barTarget.hidden = true;
        }
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = this.errorLabelValue;
        }
        // Same file re-picked must fire `change` again.
        this.inputTarget.value = '';
    }

    #progress(percent) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = this.uploadingLabelValue.replace('%percent%', `${percent} %`);
        }
        if (this.hasFillTarget) {
            this.fillTarget.style.setProperty('--progress', `${percent}%`);
        }
    }
}
