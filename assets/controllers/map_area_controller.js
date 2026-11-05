/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Styles the circles of a ux-map Google map as a soft "area" in the brand colour,
 * so a map shows a zone (e.g. the district a client settled in) rather than an exact address.
 *
 * Put it on an element that exists before the map (e.g. the lazy-map wrapper):
 *   data-controller="map-area" data-action="ux:map:circle:before-create->map-area#style"
 */
export default class extends Controller {
    static values = {
        color: { type: String, default: '#71172e' },
    }

    style(event) {
        const { definition } = event.detail
        definition.bridgeOptions = {
            ...(definition.bridgeOptions ?? {}),
            strokeColor: this.colorValue,
            strokeOpacity: 0.8,
            strokeWeight: 2,
            fillColor: this.colorValue,
            fillOpacity: 0.12,
            clickable: false,
        }
    }
}
