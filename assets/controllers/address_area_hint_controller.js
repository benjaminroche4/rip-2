/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Soft geographic guard under an address field: places-autocomplete only
 * keeps Paris predictions, so when Google matched the query but every result
 * was filtered out (places-autocomplete:outside), the dropdown stays empty
 * and the user is left guessing. This surfaces a gentle explanation instead.
 * Typing again hides it until the next empty result set.
 */
export default class extends Controller {
    static targets = ['hint']

    show() {
        this.hintTarget.classList.remove('hidden')
    }

    hide() {
        this.hintTarget.classList.add('hidden')
    }
}
