/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Drives the "More filters" modal: open/close (with body scroll lock, Escape,
 * backdrop dismissal and a focus trap), a client-side "N selected" counter that
 * stays in sync as the user toggles filters, and a client-side reset that clears
 * every control without a server round-trip (so the modal stays open).
 *
 * Visibility is driven by the `data-open` attribute on the controller element
 * (panel/backdrop animate via `group-data-open/mf` classes). Focus is trapped
 * inside the dialog while open and restored to the trigger on close (WCAG dialog).
 */
export default class extends Controller {
    static targets = ['count', 'dialog']

    #open = false
    #trigger = null
    #focusTimer = null

    #focusables() {
        if (!this.hasDialogTarget) return []
        const selector =
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        return Array.from(this.dialogTarget.querySelectorAll(selector)).filter((el) => null !== el.offsetParent)
    }

    open() {
        if (this.#open) return
        this.#open = true
        this.#trigger = this.element.querySelector('button[aria-haspopup]')
        this.element.setAttribute('data-open', '')
        this.#trigger?.setAttribute('aria-expanded', 'true')
        document.body.style.overflow = 'hidden'
        document.addEventListener('keydown', this.#onKeydown)
        this.recount()
        // Move focus inside once the open transition (visibility) has settled.
        this.#focusTimer = setTimeout(() => {
            const focusables = this.#focusables()
            ;(focusables[0] ?? (this.hasDialogTarget ? this.dialogTarget : null))?.focus()
        }, 220)
    }

    close() {
        if (!this.#open) return
        this.#open = false
        clearTimeout(this.#focusTimer)
        this.element.removeAttribute('data-open')
        this.#trigger?.setAttribute('aria-expanded', 'false')
        document.body.style.overflow = ''
        document.removeEventListener('keydown', this.#onKeydown)
        this.#trigger?.focus()
    }

    /** Recompute the "N selected" badge from the current DOM state. */
    recount() {
        if (!this.hasCountTarget) return
        let n = 0
        this.element.querySelectorAll('input[type=checkbox]').forEach((i) => i.checked && n++)
        if (this.element.querySelector('input[type=radio]:checked')) n++
        // Budget is a MAX-rent slider: the ceiling means "no maximum", so it
        // only counts as an active filter once dragged below its max.
        const slider = this.element.querySelector('input[type=range]')
        if (slider && Number(slider.value) < Number(slider.max)) n++
        this.countTarget.textContent = String(n)
    }

    /** Clear every control client-side, syncing the (no-render) draft props. */
    reset() {
        this.element.querySelectorAll('input[type=checkbox]:checked, input[type=radio]:checked').forEach((i) => {
            i.checked = false
            i.dispatchEvent(new Event('input', { bubbles: true }))
            i.dispatchEvent(new Event('change', { bubbles: true }))
        })
        const slider = this.element.querySelector('input[type=range]')
        if (slider) {
            // Max-rent slider: "no maximum" is the ceiling, so reset to max.
            slider.value = slider.max
            slider.dispatchEvent(new Event('input', { bubbles: true }))
            slider.dispatchEvent(new Event('change', { bubbles: true }))
        }
        this.recount()
    }

    #onKeydown = (event) => {
        if ('Escape' === event.key) {
            this.close()
            return
        }
        if ('Tab' !== event.key) return

        const focusables = this.#focusables()
        if (0 === focusables.length) return

        const first = focusables[0]
        const last = focusables[focusables.length - 1]
        const active = document.activeElement
        const inside = this.hasDialogTarget && this.dialogTarget.contains(active)

        if (event.shiftKey) {
            if (active === first || !inside) {
                event.preventDefault()
                last.focus()
            }
        } else if (active === last || !inside) {
            event.preventDefault()
            first.focus()
        }
    }

    disconnect() {
        clearTimeout(this.#focusTimer)
        document.removeEventListener('keydown', this.#onKeydown)
        document.body.style.overflow = ''
    }
}
