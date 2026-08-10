/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * "Check all" link on a group of checkboxes: checks every box in the
 * controller's scope, or unchecks them all when they are already all
 * checked. Each mutated input gets a bubbling `change` event so the
 * LiveComponent model sync (and the autosave it triggers) stays in step.
 */
export default class extends Controller {
    toggle() {
        const boxes = [...this.element.querySelectorAll('input[type="checkbox"]')];
        if (0 === boxes.length) {
            return;
        }

        const targetState = !boxes.every((box) => box.checked);
        for (const box of boxes) {
            if (box.checked !== targetState) {
                box.checked = targetState;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }
}
