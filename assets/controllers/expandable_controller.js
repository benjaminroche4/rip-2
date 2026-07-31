/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['content', 'trigger']

    connect() {
        // If the clamped content doesn't actually overflow, the trigger is
        // useless — drop it.
        requestAnimationFrame(() => {
            if (this.hasTriggerTarget && this.contentTarget.scrollHeight <= this.contentTarget.clientHeight) {
                this.triggerTarget.remove()
            }
        })
    }

    expand() {
        this.contentTarget.classList.remove('line-clamp-3', 'line-clamp-6', 'relative')
        this.triggerTarget.remove()
    }
}
