/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Pre-fills the security deposit while the user has not touched the field
 * themselves: one month of rent for an unfurnished rental, two months for a
 * furnished one (the French legal maximums).
 */
export default class extends Controller {
    static targets = ['rent', 'deposit', 'furnishing']

    #edited = false

    connect() {
        // A server-rendered value (failed submission) belongs to the user.
        if (this.hasDepositTarget && this.depositTarget.value !== '') this.#edited = true
    }

    suggest() {
        if (this.#edited || !this.hasDepositTarget || !this.hasRentTarget) return

        const rent = parseInt(this.rentTarget.value, 10)
        this.depositTarget.value = Number.isNaN(rent) ? '' : String(rent * this.#multiplier())
        this.depositTarget.dispatchEvent(new Event('input', { bubbles: true }))
    }

    markEdited(event) {
        if (event.isTrusted) this.#edited = true
    }

    #multiplier() {
        const checked = this.furnishingTargets.find((radio) => radio.checked)

        return checked && checked.value === 'furnished' ? 2 : 1
    }
}
