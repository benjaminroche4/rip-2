/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Toggles which `form` target is visible based on an `active` index.
 * Used by the Tabs and Sidebar variants of the document request form to
 * show one person's form at a time.
 *
 *   <div data-controller="person-picker"
 *        data-person-picker-active-value="0">
 *     <button data-action="click->person-picker#select"
 *             data-person-picker-index-param="0"
 *             data-person-picker-target="tab">Person 1</button>
 *     <button data-action="click->person-picker#select"
 *             data-person-picker-index-param="1"
 *             data-person-picker-target="tab">Person 2</button>
 *     <div data-person-picker-target="form">…person 1 form…</div>
 *     <div data-person-picker-target="form">…person 2 form…</div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['form', 'tab'];
    static values = { active: { type: Number, default: 0 } };

    connect() {
        this.#sync();
    }

    select(event) {
        const idx = parseInt(event.params.index, 10);
        if (!Number.isNaN(idx)) {
            this.activeValue = idx;
        }
    }

    activeValueChanged() {
        this.#sync();
    }

    #sync() {
        const active = this.activeValue;
        this.formTargets.forEach((el, i) => {
            el.toggleAttribute('hidden', i !== active);
        });
        this.tabTargets.forEach((el, i) => {
            const isActive = i === active;
            el.setAttribute('aria-selected', isActive ? 'true' : 'false');
            el.dataset.active = isActive ? 'true' : 'false';
        });
    }
}
