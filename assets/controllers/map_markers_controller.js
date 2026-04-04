import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    #selected = null
    #infoWindows = []

    connect() {
        this.element.addEventListener('ux:map:connect', this.#onMapConnect)
        this.element.addEventListener('ux:map:marker:after-create', this.#onMarkerCreated)
        this.element.addEventListener('ux:map:info-window:after-create', this.#onInfoWindowCreated)
    }

    disconnect() {
        this.element.removeEventListener('ux:map:connect', this.#onMapConnect)
        this.element.removeEventListener('ux:map:marker:after-create', this.#onMarkerCreated)
        this.element.removeEventListener('ux:map:info-window:after-create', this.#onInfoWindowCreated)
    }

    #activate(rect, text) {
        rect.setAttribute('fill', '#71172e')
        rect.setAttribute('stroke', '#71172e')
        text.setAttribute('fill', 'white')
    }

    #deactivate(rect, text) {
        rect.setAttribute('fill', 'white')
        rect.setAttribute('stroke', '#e5e7eb')
        text.setAttribute('fill', '#111827')
    }

    #deselect() {
        if (this.#selected) {
            this.#deactivate(this.#selected.rect, this.#selected.text)
            this.#selected = null
        }
        this.#infoWindows.forEach(iw => iw.close())
    }

    #onMapConnect = (event) => {
        const map = event.detail.map
        map.addListener('click', () => this.#deselect())
    }

    #onInfoWindowCreated = (event) => {
        const infoWindow = event.detail.infoWindow
        this.#infoWindows.push(infoWindow)

        infoWindow.addListener('closeclick', () => {
            if (this.#selected) {
                this.#deactivate(this.#selected.rect, this.#selected.text)
                this.#selected = null
            }
        })
    }

    #onMarkerCreated = (event) => {
        const marker = event.detail.marker

        if (!marker.content) {
            return
        }

        const rect = marker.content.querySelector('rect')
        const text = marker.content.querySelector('text')

        if (!rect || !text) {
            return
        }

        rect.style.transition = 'fill 100ms ease, stroke 100ms ease'
        text.style.transition = 'fill 100ms ease'
        marker.element.style.cursor = 'pointer'

        const markerData = { rect, text }

        marker.content.addEventListener('mouseenter', () => {
            this.#activate(rect, text)
        })

        marker.content.addEventListener('mouseleave', () => {
            if (this.#selected !== markerData) {
                this.#deactivate(rect, text)
            }
        })

        marker.addListener('click', () => {
            if (this.#selected && this.#selected !== markerData) {
                this.#deactivate(this.#selected.rect, this.#selected.text)
            }
            this.#selected = markerData
            this.#activate(rect, text)
        })
    }
}
