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
    #markersById = new Map()
    #routeLine = null

    connect() {
        this.element.addEventListener('ux:map:connect', this.#onMapConnect)
        // Survol d'une rangée de visite : son pin reste net, les autres
        // s'estompent (et inversement au départ du survol).
        this.onRowHover = (event) => this.#dimOthers(event.detail?.id)
        this.onRowLeave = () => this.#dimOthers(null)
        window.addEventListener('visit-row:hover', this.onRowHover)
        window.addEventListener('visit-row:leave', this.onRowLeave)
    }

    disconnect() {
        window.removeEventListener('visit-row:hover', this.onRowHover)
        window.removeEventListener('visit-row:leave', this.onRowLeave)
        this.element.removeEventListener('ux:map:connect', this.#onMapConnect)
        this.#markers.forEach(marker => marker.setMap(null))
        this.#markers = []
        this.#markersById.clear()
        if (this.#routeLine) {
            this.#routeLine.setMap(null)
            this.#routeLine = null
        }
        this.#map = null
    }

    /** Estompe tous les pins sauf celui de la visite survolée. */
    #dimOthers(id) {
        this.#markersById.forEach((marker, markerId) => {
            marker.setOpacity(null === id || id === undefined || markerId === id ? 1 : 0.35)
            marker.setZIndex(markerId === id ? 999 : undefined)
        })
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
            const marker = new google.maps.Marker({
                map: this.#map,
                position,
                title: visit.title,
                label: { text: visit.label, color: '#ffffff', fontSize: '12px', fontWeight: '600' },
            })
            // Survol du pin : la rangée correspondante s'illumine.
            marker.addListener('mouseover', () => {
                window.dispatchEvent(new CustomEvent('visit-pin:hover', { detail: { id: visit.id } }))
            })
            marker.addListener('mouseout', () => {
                window.dispatchEvent(new CustomEvent('visit-pin:leave'))
            })
            // Clic sur le pin : la liste défile jusqu'à la rangée.
            marker.addListener('click', () => {
                window.dispatchEvent(new CustomEvent('visit-pin:select', { detail: { id: visit.id } }))
            })
            this.#markers.push(marker)
            if (visit.id !== undefined) {
                this.#markersById.set(visit.id, marker)
            }
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
