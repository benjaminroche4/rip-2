/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Shows a loading state on a Turbo form's submit button while the request is in
 * flight: the button is disabled, its label hidden and a spinner shown. Restored
 * on `turbo:submit-end` (success or failure). Attach to the <form>; mark the
 * submit button, its label and the spinner with the matching targets.
 */
export default class extends Controller {
    static targets = ['submit', 'label', 'spinner']

    connect() {
        this.element.addEventListener('turbo:submit-start', this.#onStart)
        this.element.addEventListener('turbo:submit-end', this.#onEnd)
    }

    disconnect() {
        this.element.removeEventListener('turbo:submit-start', this.#onStart)
        this.element.removeEventListener('turbo:submit-end', this.#onEnd)
    }

    #onStart = () => this.#setLoading(true)
    #onEnd = () => this.#setLoading(false)

    #setLoading(loading) {
        if (this.hasSubmitTarget) this.submitTarget.disabled = loading
        // Inline display wins over class-based display utilities (e.g. `inline-flex`
        // would otherwise override a toggled `hidden`), so the label truly disappears.
        if (this.hasLabelTarget) this.labelTarget.style.display = loading ? 'none' : ''
        if (this.hasSpinnerTarget) this.spinnerTarget.style.display = loading ? 'inline-flex' : 'none'
    }
}
