import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['card']
    static outlets = ['map-markers']

    cardTargetConnected(card) {
        const key = card.dataset.markerKey
        if (!key) return

        card.addEventListener('mouseenter', () => {
            if (this.hasMapMarkersOutlet) {
                this.mapMarkersOutlet.highlightMarker(key)
            }
        })

        card.addEventListener('mouseleave', () => {
            if (this.hasMapMarkersOutlet) {
                this.mapMarkersOutlet.unhighlightMarker(key)
            }
        })
    }
}
