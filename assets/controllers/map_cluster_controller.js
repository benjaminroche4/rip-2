/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'
import { getComponent } from '@symfony/ux-live-component'

/**
 * Listens to Google Map zoom/bounds changes and triggers a LiveAction
 * to re-cluster markers server-side.
 */
export default class extends Controller {
    #map = null
    #debounceTimer = null
    #component = null
    #mapListeners = []

    async connect() {
        this.#component = await getComponent(this.element.closest('[data-controller*="live"]'))
        this.element.addEventListener('ux:map:connect', this.#onMapConnect)
        this.element.addEventListener('ux:map:marker:after-create', this.#onMarkerCreated)
    }

    disconnect() {
        this.element.removeEventListener('ux:map:connect', this.#onMapConnect)
        this.element.removeEventListener('ux:map:marker:after-create', this.#onMarkerCreated)
        if (this.#debounceTimer) {
            clearTimeout(this.#debounceTimer)
        }
        this.#mapListeners.forEach(l => google.maps.event.removeListener(l))
        this.#mapListeners = []
    }

    #onMapConnect = (event) => {
        this.#map = event.detail.map
        this.#mapListeners.push(this.#map.addListener('idle', this.#onMapIdle))
    }

    #onMarkerCreated = (event) => {
        const { marker, definition } = event.detail
        if (!definition.extra?.isCluster) return

        this.#mapListeners.push(marker.addListener('click', () => {
            const pos = marker.position
            this.#map.setZoom(this.#map.getZoom() + 2)
            this.#map.panTo(pos)
        }))
    }

    #onMapIdle = () => {
        if (this.#debounceTimer) {
            clearTimeout(this.#debounceTimer)
        }

        this.#debounceTimer = setTimeout(() => {
            const bounds = this.#map.getBounds()
            if (!bounds) return

            const ne = bounds.getNorthEast()
            const sw = bounds.getSouthWest()
            const zoom = this.#map.getZoom()

            this.#component.action('updateBounds', {
                zoom: zoom,
                south: sw.lat(),
                north: ne.lat(),
                west: sw.lng(),
                east: ne.lng(),
            })
        }, 400)
    }
}
