/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Plays a collapse + fade exit animation on connect, then removes the
 * element from the DOM. Attach it (server-side) to a card that no longer
 * belongs to the current list so its disappearance is smooth.
 */
export default class extends Controller {
    static values = {
        // Leaves the card readable long enough for the confirmation chip
        // and glow to register before it collapses away.
        delay: { type: Number, default: 1400 },
    }

    connect() {
        const el = this.element
        el.style.height = `${el.offsetHeight}px`
        el.style.overflow = 'hidden'

        this.delayTimeout = setTimeout(() => {
            this.frame = requestAnimationFrame(() => {
                el.style.transition = 'height 400ms cubic-bezier(0.22, 1, 0.36, 1), opacity 280ms ease-out, margin 400ms cubic-bezier(0.22, 1, 0.36, 1), padding 400ms cubic-bezier(0.22, 1, 0.36, 1)'
                el.style.height = '0px'
                el.style.opacity = '0'
                el.style.marginTop = '0px'
                el.style.paddingTop = '0px'
                el.style.paddingBottom = '0px'
                this.timeout = setTimeout(() => el.remove(), 420)
            })
        }, this.delayValue)
    }

    disconnect() {
        clearTimeout(this.delayTimeout)
        cancelAnimationFrame(this.frame)
        clearTimeout(this.timeout)
    }
}
