/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Live age badge next to a birth-date input: recomputes on every keystroke
 * and hides itself as soon as the date is cleared or invalid, without
 * waiting for a server round-trip.
 */
export default class extends Controller {
    static targets = ['input', 'badge'];
    static values = { template: String };

    connect() {
        this.refresh();
    }

    refresh() {
        const age = this.#age(this.inputTarget.value);
        if (age === null) {
            this.badgeTarget.hidden = true;

            return;
        }
        this.badgeTarget.textContent = this.templateValue.replace('%age%', age);
        this.badgeTarget.hidden = false;
    }

    #age(raw) {
        if (!raw) {
            return null;
        }
        const date = new Date(raw);
        if (Number.isNaN(date.getTime())) {
            return null;
        }
        const today = new Date();
        let age = today.getFullYear() - date.getFullYear();
        const monthDiff = today.getMonth() - date.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < date.getDate())) {
            age -= 1;
        }

        return age >= 0 && age < 130 ? age : null;
    }
}
