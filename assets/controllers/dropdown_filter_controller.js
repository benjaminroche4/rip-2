/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Client-side search inside a <details> dropdown panel: the input filters
 * the item targets by their data-dropdown-filter-text (case- and
 * accent-insensitive), the empty target shows when nothing matches. The
 * filter resets (and the input grabs focus) each time the details opens.
 * Attach to the <details> element, alongside details-dropdown.
 */
export default class extends Controller {
    static targets = ['input', 'item', 'empty']

    connect() {
        this.onToggle = () => {
            if (this.element.open) {
                this.reset()
                this.inputTarget.focus()
            }
        }
        this.element.addEventListener('toggle', this.onToggle)
    }

    disconnect() {
        this.element.removeEventListener('toggle', this.onToggle)
    }

    filter() {
        const needle = this.#normalize(this.inputTarget.value)
        let visible = 0
        for (const item of this.itemTargets) {
            const match = '' === needle || this.#normalize(item.dataset.dropdownFilterText ?? '').includes(needle)
            item.hidden = !match
            if (match) visible++
        }
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible > 0
        }
    }

    reset() {
        this.inputTarget.value = ''
        this.filter()
    }

    #normalize(text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim()
    }
}
