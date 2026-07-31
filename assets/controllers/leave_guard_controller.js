/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Warns before leaving a public form that holds unsent input: the native
 * beforeunload prompt covers hard unloads (reload, tab close), a confirm()
 * covers Turbo visits (in-app link clicks). The flag clears on a real
 * submit so sending the form never prompts.
 *
 * Wiring (on the <form>):
 *   data-controller="leave-guard"
 *   data-leave-guard-message-value="..."
 *   data-action="input->leave-guard#markDirty change->leave-guard#markDirty submit->leave-guard#submit"
 *
 * Blocks saved through a LiveComponent action (no <form> submit) can pass
 * `data-leave-guard-clean-event-value="my:saved"`: the component dispatches
 * that browser event on success and the guard disarms.
 */
export default class extends Controller {
    static values = { message: String, cleanEvent: String }

    #dirty = false

    connect() {
        this.onBeforeUnload = (event) => {
            if (!this.#dirty) return
            event.preventDefault()
            // Required by some browsers to actually show the native prompt.
            event.returnValue = ''
        }
        this.onBeforeVisit = (event) => {
            if (!this.#dirty) return
            if (window.confirm(this.messageValue)) {
                this.#dirty = false
            } else {
                event.preventDefault()
            }
        }
        this.onClean = () => {
            this.#dirty = false
        }
        window.addEventListener('beforeunload', this.onBeforeUnload)
        document.addEventListener('turbo:before-visit', this.onBeforeVisit)
        if (this.cleanEventValue) {
            window.addEventListener(this.cleanEventValue, this.onClean)
        }
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.onBeforeUnload)
        document.removeEventListener('turbo:before-visit', this.onBeforeVisit)
        if (this.cleanEventValue) {
            window.removeEventListener(this.cleanEventValue, this.onClean)
        }
    }

    markDirty() {
        this.#dirty = true
    }

    // A submit blocked by another controller (client-side gate) keeps the
    // guard armed; only a real submission clears it.
    submit(event) {
        if (!event.defaultPrevented) this.#dirty = false
    }
}
