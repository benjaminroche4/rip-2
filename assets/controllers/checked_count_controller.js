/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Small counter badge next to a checkbox group title (e.g. amenities):
 * shows how many boxes are checked, hidden at zero.
 */
export default class extends Controller {
    static targets = ['box', 'badge']

    connect() {
        this.update()
    }

    update() {
        const count = this.boxTargets.filter((box) => box.checked).length
        this.badgeTarget.classList.toggle('hidden', 0 === count)
        this.badgeTarget.textContent = String(count)
    }
}
