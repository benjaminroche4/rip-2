/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Submits the form through XHR when photos are attached so a real upload
 * percentage can be shown: a native POST carrying 10-15 photos on a mobile
 * connection looks frozen behind a spinner. The response (redirected success
 * page or 422 re-render) is parsed and the form container is swapped in
 * place; Stimulus reconnects the controllers on the fresh markup. Without
 * photos the native submit proceeds untouched.
 *
 * Must be wired AFTER the other submit handlers on the form: it only engages
 * when none of them prevented the submission.
 */
export default class extends Controller {
    static targets = ['status', 'fileInput']
    static values = {
        label: String,
        swapId: { type: String, default: 'listing-form' },
    }

    #xhr = null

    start(event) {
        if (event.defaultPrevented || this.#xhr) return
        if (!this.hasFileInputTarget || 0 === (this.fileInputTarget.files?.length ?? 0)) return

        event.preventDefault()

        const xhr = new XMLHttpRequest()
        this.#xhr = xhr
        xhr.open('POST', this.element.action)
        xhr.upload.addEventListener('progress', (progress) => {
            if (!progress.lengthComputable) return
            const percent = Math.round((progress.loaded / progress.total) * 100)
            this.#status(this.labelValue.replace('%percent%', `${percent} %`))
        })
        xhr.addEventListener('load', () => this.#swap(xhr.responseText, xhr.responseURL))
        // Network failure before the server processed anything: retry with a
        // plain native submit so the user is never stuck.
        xhr.addEventListener('error', () => {
            this.#xhr = null
            this.element.submit()
        })
        xhr.send(new FormData(this.element))
    }

    disconnect() {
        this.#xhr?.abort()
        this.#xhr = null
    }

    #swap(html, responseUrl) {
        this.#xhr = null
        const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById(this.swapIdValue)
        const current = document.getElementById(this.swapIdValue)
        if (!fresh || !current) {
            // Unexpected response shape: a plain GET shows the outcome
            // without re-posting anything.
            window.location.assign(responseUrl || window.location.href)
            return
        }

        current.replaceWith(fresh)
        const behavior = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
        fresh.scrollIntoView({ behavior, block: 'start' })
    }

    #status(text) {
        if (!this.hasStatusTarget) return
        this.statusTarget.classList.remove('hidden')
        this.statusTarget.textContent = text
    }
}
