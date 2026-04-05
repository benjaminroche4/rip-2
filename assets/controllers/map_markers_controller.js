import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    #map = null
    #selected = null
    #infoWindows = []
    #markersByPropertyId = new Map()
    #clusterByPropertyId = new Map()
    #highlightedCluster = null

    connect() {
        this.element.addEventListener('ux:map:connect', this.#onMapConnect)
        this.element.addEventListener('ux:map:marker:after-create', this.#onMarkerCreated)
        this.element.addEventListener('ux:map:info-window:after-create', this.#onInfoWindowCreated)
    }

    disconnect() {
        this.element.removeEventListener('ux:map:connect', this.#onMapConnect)
        this.element.removeEventListener('ux:map:marker:after-create', this.#onMarkerCreated)
        this.element.removeEventListener('ux:map:info-window:after-create', this.#onInfoWindowCreated)
        this.#markersByPropertyId.clear()
        this.#clusterByPropertyId.clear()
    }

    highlightMarker(event) {
        const id = event.currentTarget.dataset.propertyId
        const data = this.#markersByPropertyId.get(id)

        if (data) {
            this.#activate(data.rect, data.text)
        } else {
            const cluster = this.#clusterByPropertyId.get(id)
            if (cluster) this.#activateCluster(cluster)
        }
    }

    unhighlightMarker(event) {
        const id = event.currentTarget.dataset.propertyId
        const data = this.#markersByPropertyId.get(id)

        if (data && this.#selected !== data) {
            this.#deactivate(data.rect, data.text)
        }

        this.#deactivateCluster()
    }

    #activateCluster(cluster) {
        this.#highlightedCluster = cluster
        cluster.outerCircle.setAttribute('fill', '#71172e')
        cluster.innerCircle.setAttribute('fill', '#71172e')
    }

    #deactivateCluster() {
        if (!this.#highlightedCluster) return
        const c = this.#highlightedCluster
        c.outerCircle.setAttribute('fill', '#111827')
        c.innerCircle.setAttribute('fill', '#111827')
        this.#highlightedCluster = null
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
        this.#map = event.detail.map
        this.#map.addListener('click', () => this.#deselect())
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
        const { marker, definition } = event.detail

        if (!marker.content) return

        // Cluster marker
        if (definition.extra?.isCluster) {
            const circles = marker.content.querySelectorAll('circle')
            if (circles.length >= 2) {
                const clusterData = {
                    outerCircle: circles[0],
                    innerCircle: circles[1],
                }

                circles[0].style.transition = 'fill 150ms ease'
                circles[1].style.transition = 'fill 150ms ease'

                for (const id of definition.extra.propertyIds || []) {
                    this.#clusterByPropertyId.set(id, clusterData)
                }
            }
            return
        }

        // Property marker
        const rect = marker.content.querySelector('rect')
        const text = marker.content.querySelector('text')

        if (!rect || !text) return

        rect.style.transition = 'fill 100ms ease, stroke 100ms ease'
        text.style.transition = 'fill 100ms ease'
        marker.element.style.cursor = 'pointer'

        const markerData = { rect, text }

        if (definition.extra?.propertyId) {
            this.#markersByPropertyId.set(definition.extra.propertyId, markerData)
        }

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
