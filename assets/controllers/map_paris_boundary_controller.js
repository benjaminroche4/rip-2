/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'
import { PARIS_BOUNDARY } from '../data/paris_boundary.js'

// Draws a subtle slate dotted outline of the Paris commune boundary on the Google Map.
export default class extends Controller {
    #map = null
    #overlays = []

    connect() {
        this.element.addEventListener('ux:map:connect', this.#onMapConnect)
    }

    disconnect() {
        this.element.removeEventListener('ux:map:connect', this.#onMapConnect)
        this.#overlays.forEach(o => o.setMap(null))
        this.#overlays = []
    }

    #onMapConnect = (event) => {
        this.#map = event.detail.map

        this.#overlays.push(new google.maps.Polyline({
            path: PARIS_BOUNDARY,
            strokeOpacity: 0,
            clickable: false,
            zIndex: 2,
            icons: [{
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    fillColor: '#64748b',
                    fillOpacity: 0.9,
                    strokeOpacity: 0,
                    scale: 2,
                },
                offset: '0',
                repeat: '9px',
            }],
            map: this.#map,
        }))
    }
}
