/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Hides its element the instant a close control is clicked: the real removal
// only lands with the live re-render (~200ms round trip), and the frozen
// intermediate frames read as a stacking glitch. Opacity 0 (not `hidden`)
// keeps the overlay in place so it still blocks clicks toward the page
// behind until the morph actually removes it. No transition, on purpose:
// the exit must be as abrupt as the entrance.
//
// The inline style is cleared as soon as the live re-render lands, in case
// the morph recycles the node (same cleanup contract as fade_exit).
export default class extends Controller {
    run() {
        this.element.style.opacity = '0'

        document.addEventListener('live:render', () => {
            this.element.style.opacity = ''
        }, { once: true, capture: true })
    }
}
