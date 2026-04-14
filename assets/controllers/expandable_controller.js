/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['content', 'trigger']

    expand() {
        this.contentTarget.classList.remove('line-clamp-6', 'relative')
        this.triggerTarget.remove()
    }
}
