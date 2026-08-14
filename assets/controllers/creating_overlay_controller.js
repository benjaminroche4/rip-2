/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Full-screen "creating..." overlay shown while a marked Turbo form is in
 * flight — used for the lead -> dossier conversion, which now provisions a
 * Drive folder server-side and takes a moment. The overlay stays up until the
 * redirect navigates away (its DOM is then discarded); a failed submit takes
 * it back down so the page stays usable.
 *
 * Attach on the overlay element itself (initially `hidden`); mark the trigger
 * form with `data-creating-overlay-trigger`.
 */
export default class extends Controller {
    connect() {
        this.onStart = this.onStart.bind(this)
        this.onEnd = this.onEnd.bind(this)
        document.addEventListener('turbo:submit-start', this.onStart)
        document.addEventListener('turbo:submit-end', this.onEnd)
    }

    disconnect() {
        document.removeEventListener('turbo:submit-start', this.onStart)
        document.removeEventListener('turbo:submit-end', this.onEnd)
        document.body.style.overflow = ''
    }

    onStart(event) {
        if (this.#isTrigger(event.target)) {
            this.show()
        }
    }

    onEnd(event) {
        // On success Turbo navigates to the fresh dossier and discards this
        // DOM; only a failed submit needs the overlay taken back down.
        if (this.#isTrigger(event.target) && (!event.detail || false === event.detail.success)) {
            this.hide()
        }
    }

    show() {
        this.element.classList.remove('hidden')
        document.body.style.overflow = 'hidden'
    }

    hide() {
        this.element.classList.add('hidden')
        document.body.style.overflow = ''
    }

    #isTrigger(target) {
        return target instanceof HTMLFormElement && target.hasAttribute('data-creating-overlay-trigger')
    }
}
