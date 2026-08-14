/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Warns before leaving the page while something is still in flight, so a
 * half-finished action (lead -> dossier conversion, file upload, a live
 * component saving) is never lost by a reload or a closed tab.
 *
 * Three sources are watched:
 *  - Turbo form submissions (turbo:submit-start / turbo:submit-end)
 *  - Turbo fetch requests, which cover Turbo Frames and stream responses
 *  - LiveComponent actions, which flag their root with data-live-is-loading
 *
 * The native prompt covers hard unloads (reload, close, external link); a
 * confirm() covers in-app Turbo visits, which beforeunload never sees.
 * Wire it once per page, on a wrapper that outlives the content:
 *   data-controller="busy-guard" data-busy-guard-message-value="..."
 */
export default class extends Controller {
    static values = { message: String }

    /** Turbo submissions and fetches currently in flight. */
    #inFlight = 0

    connect() {
        this.onStart = () => {
            this.#inFlight += 1
        }
        this.onEnd = () => {
            this.#inFlight = Math.max(0, this.#inFlight - 1)
        }
        this.onBeforeUnload = (event) => {
            if (!this.#busy()) return
            event.preventDefault()
            // Required by some browsers to actually show the native prompt.
            event.returnValue = ''
        }
        this.onBeforeVisit = (event) => {
            // A Turbo visit triggered by our own redirect is the normal end
            // of a submission, never something to block: only guard while a
            // live component is mid-action.
            if (!this.#liveBusy()) return
            if (!window.confirm(this.messageValue)) event.preventDefault()
        }

        document.addEventListener('turbo:submit-start', this.onStart)
        document.addEventListener('turbo:submit-end', this.onEnd)
        document.addEventListener('turbo:before-fetch-request', this.onStart)
        document.addEventListener('turbo:before-fetch-response', this.onEnd)
        document.addEventListener('turbo:fetch-request-error', this.onEnd)
        window.addEventListener('beforeunload', this.onBeforeUnload)
        document.addEventListener('turbo:before-visit', this.onBeforeVisit)
    }

    disconnect() {
        document.removeEventListener('turbo:submit-start', this.onStart)
        document.removeEventListener('turbo:submit-end', this.onEnd)
        document.removeEventListener('turbo:before-fetch-request', this.onStart)
        document.removeEventListener('turbo:before-fetch-response', this.onEnd)
        document.removeEventListener('turbo:fetch-request-error', this.onEnd)
        window.removeEventListener('beforeunload', this.onBeforeUnload)
        document.removeEventListener('turbo:before-visit', this.onBeforeVisit)
    }

    #busy() {
        return this.#inFlight > 0 || this.#liveBusy()
    }

    /**
     * LiveComponent marks its root element while an action runs. Read live
     * rather than tracked: components mount and unmount with every morph,
     * so a counter would drift out of sync.
     */
    #liveBusy() {
        return null !== document.querySelector('[data-live-is-loading]')
    }
}
