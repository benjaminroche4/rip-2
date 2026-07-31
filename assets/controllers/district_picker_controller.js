/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'
import { PARIS_DISTRICTS } from '../data/paris_districts.js'

// Clickable Paris arrondissements + petite couronne polygons on a Google
// Map. Toggling a district syncs the hidden "areas" input (CSV of codes)
// and submits it to the ContactProject live component, which re-renders
// the selection chips below the map.
export default class extends Controller {
    static targets = ['input', 'map']

    #polygons = new Map()
    #onMapConnect = null

    connect() {
        this.#onMapConnect = (event) => this.#draw(event.detail.map)
        this.mapTarget.addEventListener('ux:map:connect', this.#onMapConnect)
    }

    disconnect() {
        this.mapTarget.removeEventListener('ux:map:connect', this.#onMapConnect)
        this.#polygons.forEach(p => p.setMap(null))
        this.#polygons.clear()
    }

    #draw(map) {
        for (const district of PARIS_DISTRICTS) {
            const polygon = new google.maps.Polygon({
                paths: district.path,
                map,
                zIndex: district.code.length <= 2 ? 1 : 2,
                ...this.#style(this.#selected().has(district.code)),
            })
            polygon.addListener('click', () => this.#toggle(district.code))
            this.#polygons.set(district.code, polygon)
        }
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

        this.#toggle(code)
    }

    #selected() {
        return new Set(this.inputTarget.value.split(',').map(v => v.trim()).filter(Boolean))
    }

    #toggle(code) {
        const selected = this.#selected()
        selected.has(code) ? selected.delete(code) : selected.add(code)
        this.#polygons.get(code)?.setOptions(this.#style(selected.has(code)))

        this.inputTarget.value = [...selected].join(',')
        // "input" feeds the live model, "change" triggers the autosave action.
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }))
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }))
    }
}
