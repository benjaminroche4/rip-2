/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Defer rendering of an expensive map component (e.g. Google Maps via ux-map)
 * until the container enters the viewport. Avoids blocking initial LCP on pages
 * where the map is below the fold.
 */
export default class extends Controller {
    static targets = ['placeholder', 'content']
    static values = {
        rootMargin: { type: String, default: '300px' },
    }

    #observer = null

    connect() {
        if (!this.hasContentTarget) return

        this.#observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        this.#swap()
                        this.#observer.disconnect()
                        this.#observer = null
                        break
                    }
                }
            },
            { rootMargin: this.rootMarginValue },
        )
        this.#observer.observe(this.element)
    }

    disconnect() {
        this.#observer?.disconnect()
        this.#observer = null
    }

    #swap() {
        const tpl = this.contentTarget
        const fragment = tpl.content.cloneNode(true)
        if (this.hasPlaceholderTarget) {
            this.placeholderTarget.replaceWith(fragment)
        } else {
            tpl.replaceWith(fragment)
        }
    }
}
