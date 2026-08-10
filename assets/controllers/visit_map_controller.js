/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Day-visits map: numbered pins in chronological order plus the walking
 * route between consecutive visits (1 to 2, 2 to 3, ...). The path is
 * computed server-side by WalkingRoutePlanner (Google Routes API) and
 * arrives already decoded; this controller only draws markers and a
 * polyline on the UX Map instance once connected.
 */
export default class extends Controller {
    static values = {
        // [{lat, lng, label, title}], in chronological (pin) order.
        visits: Array,
        // [{lat, lng}, ...] decoded walking path; empty when unavailable.
        route: Array,
    }

    #map = null
    #markers = []
    #routeLine = null

    connect() {
        this.element.addEventListener('ux:map:connect', this.#onMapConnect)
    }

    disconnect() {
        this.element.removeEventListener('ux:map:connect', this.#onMapConnect)
        this.#markers.forEach(marker => marker.setMap(null))
        this.#markers = []
        if (this.#routeLine) {
            this.#routeLine.setMap(null)
            this.#routeLine = null
        }
        this.#map = null
    }

    #onMapConnect = (event) => {
        this.#map = event.detail.map
        const visits = this.visitsValue

        if (visits.length === 0) {
            return
        }

        const bounds = new google.maps.LatLngBounds()
        visits.forEach((visit) => {
            const position = { lat: visit.lat, lng: visit.lng }
            bounds.extend(position)
            this.#markers.push(new google.maps.Marker({
                map: this.#map,
                position,
                title: visit.title,
                label: { text: visit.label, color: '#ffffff', fontSize: '12px', fontWeight: '600' },
            }))
        })

        if (visits.length === 1) {
            this.#map.setCenter(bounds.getCenter())
            this.#map.setZoom(15)
            return
        }

        this.#map.fitBounds(bounds, 48)

        if (this.routeValue.length >= 2) {
            this.#routeLine = new google.maps.Polyline({
                map: this.#map,
                path: this.routeValue,
                strokeColor: '#c53030',
                strokeOpacity: 0.8,
                strokeWeight: 4,
            })
        }
    }
}
