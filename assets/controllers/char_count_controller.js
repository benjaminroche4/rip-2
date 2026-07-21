/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Discreet "340/2000" counter for a length-capped field, fed by the input's
 * own maxlength attribute.
 */
export default class extends Controller {
    static targets = ['input', 'output']

    connect() {
        this.update()
    }

    update() {
        const max = this.inputTarget.maxLength
        if (max <= 0) return
        this.outputTarget.textContent = `${this.inputTarget.value.length}/${max}`
    }
}
