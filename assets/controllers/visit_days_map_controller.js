/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Même vocabulaire de carte que la marketplace (cf. MarkerBuilder) : ping
// goutte primary bordée de blanc, et libellé en pilule blanche à bord gris,
// texte gris 900. Le survol inverse la pilule en primary.
const PRIMARY = '#71172e'
const PIN_WIDTH = 32
const PIN_HEIGHT = 40
const LABEL_HEIGHT = 30
const LABEL_CHAR_WIDTH = 7.6
const LABEL_PADDING = 10

const PIN_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="${PIN_WIDTH}" height="${PIN_HEIGHT}" viewBox="0 0 32 40">
    <ellipse cx="16" cy="37" rx="6" ry="2.5" fill="#00000022"/>
    <path d="M16 1C8.27 1 2 7.27 2 15c0 9.5 12 22 13.1 23.1a1.3 1.3 0 0 0 1.8 0C18 37 30 24.5 30 15 30 7.27 23.73 1 16 1Z" fill="${PRIMARY}" stroke="#ffffff" stroke-width="2"/>
    <circle cx="16" cy="15" r="5" fill="#ffffff"/>
</svg>`

/**
 * "Prochains jours" map, revealed by the section header toggle. Unlike the
 * day tour (visit-map), the visits here are spread over several days: no
 * numbering and no walking route, just one ping per visit whose date shows
 * up on hover, in the same pill as the marketplace markers.
 *
 * The map is rendered hidden, so Google lays it out in a zero-sized box: the
 * first reveal resizes the instance and re-fits the bounds, otherwise the
 * tiles stay grey.
 */
export default class extends Controller {
    static targets = ['frame', 'showLabel', 'hideLabel']
    static values = {
        // [{id, lat, lng, address, title}] — title is the human-readable date,
        // address the fallback when the visit has no stored coordinates.
        visits: Array,
    }

    #map = null
    #markers = []
    #bounds = null
    #label = null
    #geocoded = new Map()
    #drawSeq = 0

    connect() {
        this.frameTarget.addEventListener('ux:map:connect', this.#onMapConnect)
        // Ce controller est lazy : le controller UX Map a pu se connecter (et
        // émettre ux:map:connect) avant nous. Sans ce rattrapage, l'événement
        // est déjà passé et la carte reste sans aucun point.
        this.#adoptConnectedMap()
    }

    disconnect() {
        this.#drawSeq++
        this.frameTarget.removeEventListener('ux:map:connect', this.#onMapConnect)
        this.#markers.forEach(marker => marker.setMap(null))
        this.#markers = []
        this.#hideLabel()
        this.#map = null
    }

    toggle() {
        const hidden = this.frameTarget.classList.toggle('hidden')
        this.showLabelTarget.classList.toggle('hidden', !hidden)
        this.hideLabelTarget.classList.toggle('hidden', hidden)
        if (!hidden) {
            this.#refreshViewport()
        }
    }

    /** Instance Google déjà créée par le controller UX Map, s'il a pris les devants. */
    #adoptConnectedMap() {
        // querySelector assumé : l'élément appartient au bundle UX Map (tiers), pas à notre markup, donc aucun target Stimulus possible.
        const element = this.frameTarget.querySelector('[data-controller*="map"]')
        if (!element) {
            return
        }
        for (const identifier of (element.dataset.controller ?? '').split(/\s+/)) {
            const controller = this.application.getControllerForElementAndIdentifier(element, identifier)
            if (controller?.map) {
                this.#draw(controller.map)

                return
            }
        }
    }

    #refreshViewport() {
        if (!this.#map || !window.google) {
            return
        }
        // Deferred by a frame: the resize must fire after the browser laid out
        // the freshly revealed container, otherwise the tiles stay grey.
        requestAnimationFrame(() => {
            google.maps.event.trigger(this.#map, 'resize')
            this.#fit()
        })
    }

    #fit() {
        if (!this.#bounds) {
            return
        }
        if (this.#markers.length === 1) {
            this.#map.setCenter(this.#bounds.getCenter())
            this.#map.setZoom(15)

            return
        }
        this.#map.fitBounds(this.#bounds, 48)
    }

    #onMapConnect = (event) => this.#draw(event.detail.map)

    async #draw(map) {
        if (this.#map) {
            return
        }
        this.#map = map
        const visits = this.visitsValue
        if (visits.length === 0) {
            return
        }

        const seq = ++this.#drawSeq
        this.#bounds = new google.maps.LatLngBounds()
        for (const visit of visits) {
            // Visite créée sans passer par l'autocomplétion Places : pas de
            // coordonnées en base, on géocode l'adresse ici plutôt que de
            // laisser le ping manquer à l'appel.
            const position = await this.#position(visit)
            if (seq !== this.#drawSeq) return
            if (!position) continue

            this.#bounds.extend(position)
            const marker = new google.maps.Marker({
                map: this.#map,
                position,
                title: visit.title,
                icon: {
                    url: this.#dataUri(PIN_SVG),
                    scaledSize: new google.maps.Size(PIN_WIDTH, PIN_HEIGHT),
                    anchor: new google.maps.Point(PIN_WIDTH / 2, PIN_HEIGHT),
                },
            })
            // Survol du ping : la date s'affiche dans une pilule au-dessus,
            // sans attendre le tooltip natif du navigateur.
            marker.addListener('mouseover', () => this.#showLabel(position, visit.title))
            marker.addListener('mouseout', () => this.#hideLabel())
            this.#markers.push(marker)
            this.#fit()
        }
    }

    /** Coordonnées de la visite : celles de la base, sinon un géocodage caché. */
    #position(visit) {
        if (Number.isFinite(visit.lat) && Number.isFinite(visit.lng)) {
            return Promise.resolve({ lat: visit.lat, lng: visit.lng })
        }
        if (!visit.address) {
            return Promise.resolve(null)
        }
        if (!this.#geocoded.has(visit.address)) {
            this.#geocoded.set(visit.address, new Promise((resolve) => {
                new google.maps.Geocoder().geocode({ address: visit.address, region: 'fr' }, (results, status) => {
                    resolve('OK' === status && results?.[0] ? results[0].geometry.location : null)
                })
            }))
        }

        return this.#geocoded.get(visit.address)
    }

    #showLabel(position, text) {
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
                // Ancre sous la pilule : elle se pose juste au-dessus du ping.
                anchor: new google.maps.Point(width / 2, PIN_HEIGHT + LABEL_HEIGHT - 4),
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
        return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    }

    #dataUri(svg) {
        return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`
    }
}
