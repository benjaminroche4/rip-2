/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * intl-tel-input measures the dial-code box at init to compute the input's
 * left padding. On a hard page load its stylesheet (injected async by the
 * importmap) can apply after that measurement, leaving the padding too small
 * and the typed text starting under the dial code. Re-trigger the
 * measurement once the page, stylesheets included, is fully loaded.
 */
export default class extends Controller {
    static targets = ['input']

    connect() {
        this.boundRefresh = () => this.#refresh()
        window.addEventListener('load', this.boundRefresh, { once: true })
        // Safety net when load already fired or the stylesheet was slow.
        this.timer = setTimeout(this.boundRefresh, 500)
    }

    disconnect() {
        window.removeEventListener('load', this.boundRefresh)
        clearTimeout(this.timer)
    }

    #refresh() {
        const iti = this.hasInputTarget ? this.inputTarget.iti : null
        if (!iti) return

        const country = iti.getSelectedCountryData()
        // Re-selecting the current country re-runs the padding computation.
        if (country && country.iso2) iti.setCountry(country.iso2)
    }
}
