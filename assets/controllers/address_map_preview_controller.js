/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Reveals a small map with a pin under an address field once a place has been
 * picked in the places-autocomplete dropdown. Hidden again if the field is
 * cleared. The Maps script is guaranteed to be present here: the select event
 * can only fire after places-autocomplete has loaded it.
 */
export default class extends Controller {
    static targets = ['container', 'map', 'input']
    static values = {
        // Google cloud map id, so the preview shares the marketplace map styling.
        mapId: { type: String, default: '' },
    }

    #map = null
    #marker = null

    show(event) {
        const location = event.detail?.place?.geometry?.location
        if (!location || typeof google === 'undefined') return

        const position = { lat: location.lat(), lng: location.lng() }

        this.containerTarget.classList.remove('hidden')
        requestAnimationFrame(() => this.containerTarget.classList.remove('opacity-0'))

        if (!this.#map) {
            this.#map = new google.maps.Map(this.mapTarget, {
                center: position,
                zoom: 17,
                disableDefaultUI: true,
                clickableIcons: false,
                gestureHandling: 'cooperative',
                keyboardShortcuts: false,
                ...(this.mapIdValue ? { mapId: this.mapIdValue } : {}),
            })
            this.#marker = new google.maps.Marker({ map: this.#map, position })
        } else {
            this.#map.setCenter(position)
            this.#marker.setPosition(position)
        }
    }

    // Typing again after a selection invalidates the picked place; the map
    // only reflects a confirmed address.
    onInput() {
        if (this.hasInputTarget && this.inputTarget.value.trim() === '') this.hide()
    }

    hide() {
        this.containerTarget.classList.add('opacity-0', 'hidden')
    }

    disconnect() {
        this.#map = null
        this.#marker = null
    }
}
