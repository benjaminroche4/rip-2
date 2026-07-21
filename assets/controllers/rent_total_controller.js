/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Shows the all-inclusive monthly total as soon as both rent and charges are
 * filled, so the owner never has to do the math. The wording and layout live
 * in the template: this controller only reveals the output block and fills
 * the rent / charges / total spans.
 */
export default class extends Controller {
    static targets = ['rent', 'charges', 'output', 'amount', 'rentOut', 'chargesOut']

    update() {
        const rent = parseInt(this.rentTarget.value, 10)
        const charges = parseInt(this.chargesTarget.value, 10)
        const complete = !Number.isNaN(rent) && !Number.isNaN(charges)

        this.outputTarget.classList.toggle('hidden', !complete)
        if (!complete) return

        const format = (value) => value.toLocaleString(document.documentElement.lang || 'fr')
        this.amountTarget.textContent = format(rent + charges)
        this.rentOutTarget.textContent = format(rent)
        this.chargesOutTarget.textContent = format(charges)
    }
}
