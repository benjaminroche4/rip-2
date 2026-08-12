/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'
import { getComponent } from '@symfony/ux-live-component'

// Client half of the Cmd+K palette: forwards open/close to the live
// component (visibility is server state, so morphs never fight it) and
// handles the pure-UI parts: focusing the input and arrow-key navigation
// over the rendered result links.
export default class extends Controller {
    static targets = ['modal', 'input', 'hit']

    #component = null
    #focusOnRender = false
    #index = -1
    #onRender = null

    async initialize() {
        // The controller sits on an inner wrapper; the live component owns
        // the parent root element.
        this.#component = await getComponent(this.element.closest('[data-live-name-value]'))
        this.#onRender = () => {
            // Fresh results: the highlight targets are new nodes.
            this.#index = -1
            if (this.#focusOnRender && !this.modalTarget.hidden) {
                this.#focusOnRender = false
                this.inputTarget.focus()
            }
        }
        this.#component.on('render:finished', this.#onRender)
    }

    disconnect() {
        if (this.#component && this.#onRender) {
            this.#component.off('render:finished', this.#onRender)
        }
    }

    open() {
        if (!this.modalTarget.hidden) {
            this.inputTarget.focus()

            return
        }
        this.#focusOnRender = true
        this.#component.action('openSearch')
    }

    close() {
        if (this.modalTarget.hidden) {
            return
        }
        this.#component.action('closeSearch')
    }

    next() {
        this.#highlight(this.#index + 1)
    }

    prev() {
        this.#highlight(this.#index - 1)
    }

    go() {
        const hit = this.hitTargets[this.#index] ?? this.hitTargets[0]
        hit?.click()
    }

    #highlight(index) {
        const hits = this.hitTargets
        if (0 === hits.length) {
            return
        }
        this.#index = (index + hits.length) % hits.length
        hits.forEach((hit, i) => hit.classList.toggle('bg-neutral-100', i === this.#index))
        hits[this.#index].scrollIntoView({ block: 'nearest' })
    }
}
