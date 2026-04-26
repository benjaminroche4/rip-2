/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    connect() {
        this.element.addEventListener('ux:map:pre-connect', this.#onPreConnect)
    }

    disconnect() {
        this.element.removeEventListener('ux:map:pre-connect', this.#onPreConnect)
    }

    #onPreConnect = (event) => {
        event.detail.options.scrollwheel = false
        event.detail.options.gestureHandling = 'greedy'
    }
}
