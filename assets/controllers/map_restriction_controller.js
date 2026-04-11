/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Restricts the Google Map viewport to Paris + petite couronne (92, 93, 94)
export default class extends Controller {
    connect() {
        this.element.addEventListener('ux:map:pre-connect', this.#onPreConnect)
    }

    disconnect() {
        this.element.removeEventListener('ux:map:pre-connect', this.#onPreConnect)
    }

    #onPreConnect = (event) => {
        event.detail.options.restriction = {
            latLngBounds: { south: 48.60, north: 49.10, west: 1.90, east: 2.80 },
            strictBounds: true,
        }
    }
}
