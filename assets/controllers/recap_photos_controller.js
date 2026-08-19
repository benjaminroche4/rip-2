/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Photo header of the visit recap card: mirrors the photos selected in the
 * form (visit-photos:changed window event from photo-preview) as a listing
 * style cover, with a "+n" badge when several are selected. Lives inside a
 * data-live-ignore zone: re-renders never wipe it, only the events drive it.
 */
export default class extends Controller {
    static targets = ['zone', 'cover', 'count'];

    connect() {
        this.onChanged = (event) => this.#render(event.detail?.urls ?? []);
        window.addEventListener('visit-photos:changed', this.onChanged);
    }

    disconnect() {
        window.removeEventListener('visit-photos:changed', this.onChanged);
    }

    #render(urls) {
        const has = urls.length > 0;
        this.zoneTarget.hidden = !has;
        if (!has) {
            this.coverTarget.removeAttribute('src');
            return;
        }
        this.coverTarget.src = urls[0];
        if (this.hasCountTarget) {
            this.countTarget.hidden = urls.length < 2;
            this.countTarget.textContent = `+${urls.length - 1}`;
        }
    }
}
