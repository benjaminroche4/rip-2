/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the drawer trigger's note counter in sync: the notes LiveComponent
 * dispatches 'contact-notes:count' with the fresh total after every
 * add/delete, and this controller rewrites the badge without a reload.
 */
export default class extends Controller {
    static targets = ['badge'];

    update(event) {
        const count = event?.detail?.count;
        if (this.hasBadgeTarget && count !== undefined) {
            this.badgeTarget.textContent = count;
            // Zéro note : le badge disparaît plutôt que d'afficher "0".
            this.badgeTarget.classList.toggle('hidden', 0 === count);
        }
    }
}
