/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Toggles the arrondissement filter panel: a centered dropdown on desktop, a
 * bottom sheet on mobile. Visibility is driven by the `data-open` attribute on
 * the controller element (panel/backdrop animate via `group-data-open` classes).
 * Closes on outside click, Escape, or backdrop tap.
 */
export default class extends Controller {
    static targets = ['loadTrigger']
    static values = { loaded: Boolean }

    #open = false
    #loadRequested = false
    #mobileQuery = window.matchMedia('(max-width: 1023px)')

    get #toggleButton() {
        return this.element.querySelector('button[aria-haspopup]')
    }

    toggle() {
        this.#open ? this.close() : this.open()
    }

    open() {
        if (this.#open) return
        this.#open = true
        this.element.setAttribute('data-open', '')
        this.#toggleButton?.setAttribute('aria-expanded', 'true')
        if (this.#mobileQuery.matches) document.body.style.overflow = 'hidden'

        // Lazy-load the panel content on first open.
        if (!this.loadedValue && !this.#loadRequested && this.hasLoadTriggerTarget) {
            this.#loadRequested = true
            this.loadTriggerTarget.click()
        }

        document.addEventListener('keydown', this.#onKeydown)
        // Defer so the opening click doesn't immediately close the panel.
        requestAnimationFrame(() => document.addEventListener('click', this.#onOutsideClick))
    }

    close() {
        if (!this.#open) return
        this.#open = false
        this.element.removeAttribute('data-open')
        this.#toggleButton?.setAttribute('aria-expanded', 'false')
        document.body.style.overflow = ''

        document.removeEventListener('keydown', this.#onKeydown)
        document.removeEventListener('click', this.#onOutsideClick)
    }

    #onKeydown = (event) => {
        if (event.key === 'Escape') this.close()
    }

    #onOutsideClick = (event) => {
        if (!this.element.contains(event.target)) this.close()
    }

    disconnect() {
        document.removeEventListener('keydown', this.#onKeydown)
        document.removeEventListener('click', this.#onOutsideClick)
        document.body.style.overflow = ''
    }
}
