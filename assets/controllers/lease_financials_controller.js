/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * An Airbnb-only project has no monthly rent, charges or deposit: when every
 * checked lease type is "airbnb", the financial block is hidden and its
 * inputs disabled (so they are neither submitted nor counted as required by
 * the steps gate). Server-side, the submission DTO applies the same rule.
 */
export default class extends Controller {
    static targets = ['lease', 'financials', 'input']

    connect() {
        this.update()
    }

    update() {
        const checked = this.leaseTargets.filter((box) => box.checked)
        const airbnbOnly = checked.length > 0 && checked.every((box) => 'airbnb' === box.value)

        this.financialsTarget.classList.toggle('hidden', airbnbOnly)
        this.inputTargets.forEach((input) => {
            input.disabled = airbnbOnly
        })
    }
}
