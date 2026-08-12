/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'
import { PARIS_DISTRICTS } from '../data/paris_districts.js'

// Inner 24x24 lucide strokes of the important-address type icons, embedded
// in the marker badge SVG (Google Maps markers cannot reference ux_icon).
const PIN_ICONS = {
    work: '<path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/>',
    school: '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0zM22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
    daycare: '<path d="M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5m1-4h.01"/><path d="M19.38 6.813A9 9 0 0 1 20.8 10.2a2 2 0 0 1 0 3.6a9 9 0 0 1-17.6 0a2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-2 2.5c-.8 0-1.5-.4-1.5-1m-3 5h.01"/>',
    family: '<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676a.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/>',
    gym: '<path d="M17.596 12.768a2 2 0 1 0 2.829-2.829l-1.768-1.767a2 2 0 0 0 2.828-2.829l-2.828-2.828a2 2 0 0 0-2.829 2.828l-1.767-1.768a2 2 0 1 0-2.829 2.829zM2.5 21.5l1.4-1.4M20.1 3.9l1.4-1.4M5.343 21.485a2 2 0 1 0 2.829-2.828l1.767 1.768a2 2 0 1 0 2.829-2.829l-6.364-6.364a2 2 0 1 0-2.829 2.829l1.768 1.767a2 2 0 0 0-2.828 2.829zM9.6 14.4l4.8-4.8"/>',
    other: '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
}
const PIN_COLOR = '#71172e'

// Clickable Paris arrondissements + petite couronne polygons on a Google
// Map. Toggling a district syncs the hidden "areas" input (CSV of codes)
// and submits it to the ContactProject live component, which re-renders
// the selection chips below the map. The optional "pins" value drops one
// badge marker per important address (icon = destination type).
export default class extends Controller {
    static targets = ['input', 'map', 'frame', 'backdrop', 'expandButton', 'collapseButton']
    static values = {
        pins: { type: Array, default: [] },
        // Padlock state: the map stays browsable (pan, zoom, fullscreen)
        // but the district polygons stop responding to clicks.
        locked: { type: Boolean, default: false },
    }

    #expanded = false
    #mapStyle = null

    #polygons = new Map()
    #onMapConnect = null
    #map = null
    #markers = []
    #renderSeq = 0
    #geocoded = new Map()

    connect() {
        this.#onMapConnect = (event) => this.#draw(event.detail.map)
        this.mapTarget.addEventListener('ux:map:connect', this.#onMapConnect)
    }

    disconnect() {
        if (this.#expanded) {
            this.collapse()
        }
        this.mapTarget.removeEventListener('ux:map:connect', this.#onMapConnect)
        this.#polygons.forEach(p => p.setMap(null))
        this.#polygons.clear()
        this.#renderSeq++
        this.#markers.forEach(m => m.setMap(null))
        this.#markers = []
        this.#map = null
    }

    // ── Plein écran : la carte passe en overlay fixe, l'instance Google
    //    est resize/recentrée, Échap ou le bouton referment. ──
    expand() {
        if (this.#expanded) {
            return
        }
        this.#expanded = true
        this.#mapStyle = this.mapTarget.getAttribute('style')
        // "relative" must go: Tailwind emits it after "fixed" in the
        // stylesheet, so keeping both leaves the frame in-flow (no overlay).
        this.frameTarget.classList.remove('relative')
        this.frameTarget.classList.add('fixed', 'inset-3', 'z-50', 'sm:inset-6', 'shadow-2xl')
        this.mapTarget.style.height = '100%'
        this.backdropTarget.classList.replace('hidden', 'block')
        this.expandButtonTarget.classList.replace('flex', 'hidden')
        this.collapseButtonTarget.classList.replace('hidden', 'flex')
        document.body.style.overflow = 'hidden'
        this.#refreshMapViewport()
    }

    collapse() {
        if (!this.#expanded) {
            return
        }
        this.#expanded = false
        this.frameTarget.classList.remove('fixed', 'inset-3', 'z-50', 'sm:inset-6', 'shadow-2xl')
        this.frameTarget.classList.add('relative')
        if (null !== this.#mapStyle) {
            this.mapTarget.setAttribute('style', this.#mapStyle)
        }
        this.backdropTarget.classList.replace('block', 'hidden')
        this.expandButtonTarget.classList.replace('hidden', 'flex')
        this.collapseButtonTarget.classList.replace('flex', 'hidden')
        document.body.style.overflow = ''
        this.#refreshMapViewport()
    }

    #refreshMapViewport() {
        // Deferred by a frame: the resize must fire AFTER the browser laid
        // out the new container size, otherwise the tiles stay grey on the
        // freshly revealed surface. Second frame as a belt for slower
        // layouts (fonts, scrollbar removal shifting widths).
        requestAnimationFrame(() => {
            this.#redrawMap()
            requestAnimationFrame(() => this.#redrawMap())
        })
    }

    #redrawMap() {
        if (!this.#map || !window.google) {
            return
        }
        const center = this.#map.getCenter()
        google.maps.event.trigger(this.#map, 'resize')
        if (center) {
            this.#map.setCenter(center)
        }
    }

    #draw(map) {
        this.#map = map
        for (const district of PARIS_DISTRICTS) {
            const polygon = new google.maps.Polygon({
                paths: district.path,
                map,
                zIndex: district.code.length <= 2 ? 1 : 2,
                clickable: !this.lockedValue,
                ...this.#style(this.#selected().has(district.code)),
            })
            polygon.addListener('click', () => this.#toggle(district.code))
            this.#polygons.set(district.code, polygon)
        }
        this.#renderPins()
    }

    // Fired by the live morph when the padlock is toggled.
    lockedValueChanged() {
        this.#polygons.forEach(p => p.setOptions({ clickable: !this.lockedValue }))
    }

    // Fired by the live morph when an address is added or removed.
    pinsValueChanged() {
        if (this.#map) {
            this.#renderPins()
        }
    }

    async #renderPins() {
        const seq = ++this.#renderSeq
        this.#markers.forEach(m => m.setMap(null))
        this.#markers = []
        for (const pin of this.pinsValue) {
            const position = await this.#position(pin)
            // A newer render (or a disconnect) superseded this one mid-await.
            if (seq !== this.#renderSeq) return
            if (!position) continue
            this.#markers.push(new google.maps.Marker({
                map: this.#map,
                position,
                title: pin.label ?? pin.address ?? '',
                icon: {
                    url: this.#badge(pin.type),
                    scaledSize: new google.maps.Size(32, 32),
                    anchor: new google.maps.Point(16, 16),
                },
            }))
        }
    }

    // Rows saved before the coordinates were captured (or typed free-form)
    // fall back to a client-side geocode, cached per address.
    #position(pin) {
        if (Number.isFinite(pin.lat) && Number.isFinite(pin.lng)) {
            return Promise.resolve({ lat: pin.lat, lng: pin.lng })
        }
        if (!pin.address) {
            return Promise.resolve(null)
        }
        if (!this.#geocoded.has(pin.address)) {
            this.#geocoded.set(pin.address, new Promise((resolve) => {
                new google.maps.Geocoder().geocode({ address: pin.address, region: 'fr' }, (results, status) => {
                    resolve('OK' === status && results?.[0] ? results[0].geometry.location : null)
                })
            }))
        }
        return this.#geocoded.get(pin.address)
    }

    #badge(type) {
        const inner = PIN_ICONS[type] ?? PIN_ICONS.other
        const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">'
            + `<circle cx="16" cy="16" r="14" fill="#fff" stroke="${PIN_COLOR}" stroke-width="2"/>`
            + `<g transform="translate(8.5 8.5) scale(0.625)" fill="none" stroke="${PIN_COLOR}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">${inner}</g>`
            + '</svg>'
        return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`
    }

    #style(selected) {
        return selected
            ? { fillColor: '#71172e', fillOpacity: 0.28, strokeColor: '#71172e', strokeOpacity: 0.9, strokeWeight: 1.5 }
            : { fillColor: '#64748b', fillOpacity: 0.06, strokeColor: '#64748b', strokeOpacity: 0.55, strokeWeight: 1 }
    }

    // Chip click on the server-rendered badges below the map. The chip fades
    // out immediately; the live re-render drops it for real just after.
    remove(event) {
        const code = event.params.code
        if (!this.#selected().has(code)) {
            return
        }

        const chip = event.currentTarget
        chip.style.transition = 'opacity 0.2s ease-out, transform 0.2s ease-out'
        chip.style.opacity = '0'
        chip.style.transform = 'scale(0.9)'
        // The morph can recycle this node for another chip: clear the fade
        // once the re-render lands, or the recycled chip stays invisible.
        document.addEventListener('live:render', () => {
            chip.style.transition = ''
            chip.style.opacity = ''
            chip.style.transform = ''
        }, { once: true, capture: true })

        this.#toggle(code)
    }

    #selected() {
        return new Set(this.inputTarget.value.split(',').map(v => v.trim()).filter(Boolean))
    }

    #toggle(code) {
        // Belt over the polygons' clickable flag: no mutation while locked.
        if (this.lockedValue) {
            return
        }
        const selected = this.#selected()
        selected.has(code) ? selected.delete(code) : selected.add(code)
        this.#polygons.get(code)?.setOptions(this.#style(selected.has(code)))

        this.inputTarget.value = [...selected].join(',')
        // "input" feeds the live model, "change" triggers the autosave action.
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }))
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }))
    }
}
