/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Clones `item` into `content` so the CSS `animate-marquee` keyframe
// (translates by -100%) loops seamlessly without a visible gap.
export default class extends Controller {
    static targets = ['content', 'item']

    connect() {
        if (!this.hasContentTarget || !this.hasItemTarget) return
        // Defer to next animation frame so the original item is laid out
        // before the clone is appended (prevents a one-frame gap on slow CPUs).
        requestAnimationFrame(() => {
            this.contentTarget.appendChild(this.itemTarget.cloneNode(true))
        })
    }
}
