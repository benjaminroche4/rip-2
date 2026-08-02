/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Admin pages show live data: when the browser resurrects one from its
// back-forward cache (event.persisted), the DOM is a frozen snapshot with
// no network round-trip. Force a fresh Turbo visit so lists and badges
// reflect the current state.
export default class extends Controller {
    #onPageShow = null

    connect() {
        this.#onPageShow = (event) => {
            if (event.persisted) {
                window.Turbo
                    ? window.Turbo.visit(window.location.href, { action: 'replace' })
                    : window.location.reload()
            }
        }
        window.addEventListener('pageshow', this.#onPageShow)
    }

    disconnect() {
        window.removeEventListener('pageshow', this.#onPageShow)
    }
}
