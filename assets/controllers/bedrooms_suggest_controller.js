/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * A studio has no separate bedroom: picking the "studio" property type
 * auto-selects 0 bedrooms. The user can still change it afterwards.
 */
export default class extends Controller {
    static targets = ['type', 'bedroom']

    suggest(event) {
        if (!this.typeTargets.includes(event.target)) return
        if (event.target.value !== 'studio' || !event.target.checked) return

        const zero = this.bedroomTargets.find((radio) => radio.value === '0')
        if (!zero || zero.checked) return

        zero.checked = true
        zero.dispatchEvent(new Event('change', { bubbles: true }))
    }
}
