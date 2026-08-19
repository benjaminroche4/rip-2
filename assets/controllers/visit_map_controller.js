/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Même vocabulaire de carte que "Prochains jours" (visit_days_map) et la
// marketplace : ping goutte primary bordé de blanc, pilule blanche à bord
// gris au survol. Seule différence : le numéro d'ordre chronologique de la
// tournée s'affiche dans la pastille du ping.
const PRIMARY = '#71172e'
const PIN_WIDTH = 32
const PIN_HEIGHT = 40
const LABEL_HEIGHT = 30
const LABEL_CHAR_WIDTH = 7.6
const LABEL_PADDING = 10

const pinSvg = (label) => `<svg xmlns="http://www.w3.org/2000/svg" width="${PIN_WIDTH}" height="${PIN_HEIGHT}" viewBox="0 0 32 40">
    <ellipse cx="16" cy="37" rx="6" ry="2.5" fill="#00000022"/>
    <path d="M16 1C8.27 1 2 7.27 2 15c0 9.5 12 22 13.1 23.1a1.3 1.3 0 0 0 1.8 0C18 37 30 24.5 30 15 30 7.27 23.73 1 16 1Z" fill="${PRIMARY}" stroke="#ffffff" stroke-width="2"/>
    <circle cx="16" cy="15" r="8" fill="#ffffff"/>
    <text x="16" y="15.5" text-anchor="middle" dominant-baseline="central" font-family="sans-serif" font-size="11" font-weight="700" fill="${PRIMARY}">${label}</text>
</svg>`

/**
 * Day-visits map: numbered pins in chronological order plus the walking
 * route between consecutive visits (1 to 2, 2 to 3, ...). The path is
 * computed server-side by WalkingRoutePlanner (Google Routes API) and
 * arrives already decoded; this controller only draws markers and a
 * dotted polyline on the UX Map instance once connected.
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
    #visitsById = new Map()
    #routeLine = null
    #label = null

    connect() {
        this.element.addEventListener('ux:map:connect', this.#onMapConnect)
        // Ce controller est lazy : le controller UX Map a pu se connecter (et
        // émettre ux:map:connect) avant nous, typiquement après une navigation
        // Turbo. Sans ce rattrapage, la carte reste sans aucun pin.
        this.#adoptConnectedMap()
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
        this.#visitsById.clear()
        this.#hideLabel()
        if (this.#routeLine) {
            this.#routeLine.setMap(null)
            this.#routeLine = null
        }
        this.#map = null
    }

    /**
     * Survol d'une rangée : son pin grossit et affiche sa pilule, les autres
     * s'estompent nettement. id null/undefined = retour à l'état neutre.
     */
    #dimOthers(id) {
        const active = null !== id && id !== undefined
        this.#markersById.forEach((marker, markerId) => {
            const hovered = active && markerId === id
            marker.setOpacity(!active || hovered ? 1 : 0.25)
            marker.setZIndex(hovered ? 999 : undefined)
            const visit = this.#visitsById.get(markerId)
            if (visit) {
                marker.setIcon(this.#pinIcon(visit.label, hovered ? 1.2 : 1))
            }
        })
        this.#hideLabel()
        if (active) {
            const visit = this.#visitsById.get(id)
            if (visit) {
                this.#showLabel({ lat: visit.lat, lng: visit.lng }, visit.title, 1.2)
            }
        }
    }

    /** Icône du ping numéroté, éventuellement grossie (survol). */
    #pinIcon(label, scale = 1) {
        return {
            url: this.#dataUri(pinSvg(this.#escape(label))),
            scaledSize: new google.maps.Size(PIN_WIDTH * scale, PIN_HEIGHT * scale),
            anchor: new google.maps.Point((PIN_WIDTH * scale) / 2, PIN_HEIGHT * scale),
        }
    }

    #onMapConnect = (event) => {
        this.#init(event.detail.map)
    }

    #adoptConnectedMap() {
        // querySelector assumé : l'élément appartient au bundle UX Map (tiers), pas à notre markup, donc aucun target Stimulus possible.
        const element = this.element.querySelector('[data-controller*="map"]')
        if (!element) {
            return
        }
        for (const identifier of (element.dataset.controller ?? '').split(/\s+/)) {
            const controller = this.application.getControllerForElementAndIdentifier(element, identifier)
            if (controller?.map) {
                this.#init(controller.map)

                return
            }
        }
    }

    #init(map) {
        this.#map = map
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
                icon: this.#pinIcon(visit.label),
            })
            // Survol du pin : la rangée correspondante s'illumine et l'heure +
            // dossier s'affichent dans la même pilule que les autres cartes.
            marker.addListener('mouseover', () => {
                this.#showLabel(position, visit.title)
                window.dispatchEvent(new CustomEvent('visit-pin:hover', { detail: { id: visit.id } }))
            })
            marker.addListener('mouseout', () => {
                this.#hideLabel()
                window.dispatchEvent(new CustomEvent('visit-pin:leave'))
            })
            // Clic sur le pin : la liste défile jusqu'à la rangée.
            marker.addListener('click', () => {
                window.dispatchEvent(new CustomEvent('visit-pin:select', { detail: { id: visit.id } }))
            })
            this.#markers.push(marker)
            if (visit.id !== undefined) {
                this.#markersById.set(visit.id, marker)
                this.#visitsById.set(visit.id, visit)
            }
        })

        if (visits.length === 1) {
            this.#map.setCenter(bounds.getCenter())
            this.#map.setZoom(15)
            return
        }

        this.#map.fitBounds(bounds, 48)

        if (this.routeValue.length >= 2) {
            // Trajet à pied entre visites consécutives : pointillés primary,
            // le style Google Maps des itinéraires piétons.
            this.#routeLine = new google.maps.Polyline({
                map: this.#map,
                path: this.routeValue,
                strokeOpacity: 0,
                icons: [{
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        fillColor: PRIMARY,
                        fillOpacity: 0.85,
                        scale: 2.5,
                        strokeOpacity: 0,
                    },
                    offset: '0',
                    repeat: '12px',
                }],
            })
        }
    }

    /** Pilule blanche au-dessus du ping survolé, comme visit_days_map. */
    #showLabel(position, text, scale = 1) {
        this.#hideLabel()
        const width = Math.round(text.length * LABEL_CHAR_WIDTH) + LABEL_PADDING * 2
        this.#label = new google.maps.Marker({
            map: this.#map,
            position,
            clickable: false,
            zIndex: 999,
            icon: {
                url: this.#dataUri(this.#labelSvg(width, text)),
                scaledSize: new google.maps.Size(width, LABEL_HEIGHT),
                anchor: new google.maps.Point(width / 2, PIN_HEIGHT * scale + LABEL_HEIGHT - 4),
            },
        })
    }

    #hideLabel() {
        this.#label?.setMap(null)
        this.#label = null
    }

    #labelSvg(width, text) {
        return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${LABEL_HEIGHT}">
            <defs>
                <filter id="s" x="-20%" y="-20%" width="140%" height="160%">
                    <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="#00000018"/>
                </filter>
            </defs>
            <rect x="0.5" y="0.5" width="${width - 1}" height="${LABEL_HEIGHT - 1}" rx="15" fill="white" stroke="#e5e7eb" stroke-width="1" filter="url(#s)"/>
            <text x="${width / 2}" y="${LABEL_HEIGHT / 2}" text-anchor="middle" dominant-baseline="central" font-family="sans-serif" font-size="13" font-weight="600" fill="#111827">${this.#escape(text)}</text>
        </svg>`
    }

    #escape(text) {
        return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    }

    #dataUri(svg) {
        return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`
    }
}
