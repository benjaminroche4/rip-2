/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Toggle the desktop listing layout between the split view (cards + map)
 * and a map-only view spanning the full width of the section. The search
 * bar lives in a separate section above and is never affected.
 *
 * Google Maps does not observe its container size, so a `resize` event is
 * dispatched after each toggle to let it recompute its viewport.
 */
export default class extends Controller {
    static targets = ['list', 'button', 'expandIcon', 'collapseIcon']
    static classes = ['gridDefault', 'gridExpanded', 'listHidden']
    static values = {
        expanded: Boolean,
        expandLabel: String,
        collapseLabel: String,
    }

    #initialized = false

    toggle() {
        this.expandedValue = !this.expandedValue
    }

    expandedValueChanged(expanded) {
        this.element.classList.toggle(this.gridDefaultClass, !expanded)
        this.element.classList.toggle(this.gridExpandedClass, expanded)

        if (this.hasListTarget) this.listTarget.classList.toggle(this.listHiddenClass, expanded)
        if (this.hasExpandIconTarget) this.expandIconTarget.classList.toggle('hidden', expanded)
        if (this.hasCollapseIconTarget) this.collapseIconTarget.classList.toggle('hidden', !expanded)

        if (this.hasButtonTarget) {
            this.buttonTarget.setAttribute('aria-pressed', expanded ? 'true' : 'false')
            this.buttonTarget.setAttribute('aria-label', expanded ? this.collapseLabelValue : this.expandLabelValue)
        }

        // Skip the first run (Stimulus fires valueChanged on connect): the map
        // is already correctly sized, no need to nudge it.
        if (!this.#initialized) {
            this.#initialized = true
            return
        }

        requestAnimationFrame(() => window.dispatchEvent(new Event('resize')))
    }
}
