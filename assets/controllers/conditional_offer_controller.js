/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Shows the "offer" radio panel only when the help-type select matches a
// given value (housing search). Hides it and clears the selection otherwise,
// so a stale offer is never submitted after the user changes their mind.
export default class extends Controller {
    static targets = ['trigger', 'panel', 'input']
    static values = { match: String }

    connect() {
        this.toggle()
    }

    toggle() {
        const show = this.hasTriggerTarget && this.triggerTarget.value === this.matchValue
        this.panelTarget.hidden = !show

        if (!show) {
            this.inputTargets.forEach((input) => { input.checked = false })
        }
    }
}
