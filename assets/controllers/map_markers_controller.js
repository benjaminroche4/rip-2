/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static values = {
        cardUrl: { type: String, default: '' },
        locale: { type: String, default: 'fr' },
    }

    #map = null
    #selected = null
    #infoWindows = []
    #markersByPropertyId = new Map()
    #clusterByPropertyId = new Map()
    #highlightedCluster = null
    #cardCache = new Map()
    #activeInfoWindow = null

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
        cluster.outerCircle.setAttribute('opacity', '0.3')
        cluster.middleCircle.setAttribute('fill', '#71172e')
        cluster.innerCircle.setAttribute('fill', '#71172e')
        cluster.text.setAttribute('fill', 'white')
    }

    #deactivateCluster() {
        if (!this.#highlightedCluster) return
        const c = this.#highlightedCluster
        c.outerCircle.setAttribute('fill', '#e5e7eb')
        c.outerCircle.setAttribute('opacity', '0.5')
        c.middleCircle.setAttribute('fill', '#f3f4f6')
        c.innerCircle.setAttribute('fill', 'white')
        c.text.setAttribute('fill', '#111827')
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
        if (this.#activeInfoWindow) {
            this.#activeInfoWindow.close()
            this.#activeInfoWindow = null
        }
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
            const text = marker.content.querySelector('text')
            if (circles.length >= 3) {
                const clusterData = {
                    outerCircle: circles[0],
                    middleCircle: circles[1],
                    innerCircle: circles[2],
                    text,
                }

                circles[0].style.transition = 'fill 150ms ease, opacity 150ms ease'
                circles[1].style.transition = 'fill 150ms ease'
                circles[2].style.transition = 'fill 150ms ease'
                if (text) text.style.transition = 'fill 150ms ease'

                marker.element.style.cursor = 'pointer'

                marker.content.addEventListener('mouseenter', () => {
                    this.#activateCluster(clusterData)
                })

                marker.content.addEventListener('mouseleave', () => {
                    if (this.#highlightedCluster === clusterData) {
                        this.#deactivateCluster()
                    }
                })

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
            if (this.#selected === markerData && this.#activeInfoWindow) {
                this.#deselect()
                return
            }
            if (this.#selected && this.#selected !== markerData) {
                this.#deactivate(this.#selected.rect, this.#selected.text)
            }
            this.#selected = markerData
            this.#activate(rect, text)
            this.#openInfoWindow(marker, definition.extra?.propertyId)
        })
    }

    async #openInfoWindow(marker, propertyId) {
        if (!propertyId || !this.#map) return

        if (this.#activeInfoWindow) {
            this.#activeInfoWindow.close()
            this.#activeInfoWindow = null
        }

        const html = await this.#fetchCardHtml(propertyId)
        if (!html) return

        const { InfoWindow } = await google.maps.importLibrary('maps')
        const infoWindow = new InfoWindow({ content: html, disableAutoPan: false })
        infoWindow.open({ map: this.#map, anchor: marker })

        infoWindow.addListener('closeclick', () => {
            if (this.#selected) {
                this.#deactivate(this.#selected.rect, this.#selected.text)
                this.#selected = null
            }
            this.#activeInfoWindow = null
        })

        this.#activeInfoWindow = infoWindow
    }

    async #fetchCardHtml(propertyId) {
        if (this.#cardCache.has(propertyId)) {
            return this.#cardCache.get(propertyId)
        }

        const url = this.cardUrlValue.replace('__ID__', encodeURIComponent(propertyId))
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' } })
            if (!response.ok) return null
            const html = await response.text()
            this.#cardCache.set(propertyId, html)
            return html
        } catch {
            return null
        }
    }
}
