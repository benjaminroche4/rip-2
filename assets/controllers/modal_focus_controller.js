/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Focus management for live modals: moves focus to the first focusable field
 * when the modal appears, keeps Tab / Shift+Tab cycling inside it, and gives
 * focus back to the previously focused element (the trigger button) when the
 * modal is removed. Attach to the modal overlay element.
 *
 * The focusable lookup is a generic query by nature — a focus trap has to
 * enumerate every focusable descendant, which static targets cannot express.
 */
export default class extends Controller {
    connect() {
        this.previouslyFocused = document.activeElement;
        this.onKeydown = this.trapTab.bind(this);
        this.element.addEventListener('keydown', this.onKeydown);
        this.focusables().find((el) => el.matches('input, select, textarea'))?.focus();
    }

    disconnect() {
        this.element.removeEventListener('keydown', this.onKeydown);
        if (this.previouslyFocused instanceof HTMLElement && this.previouslyFocused.isConnected) {
            this.previouslyFocused.focus();
        }
    }

    trapTab(event) {
        if (event.key !== 'Tab') {
            return;
        }
        const items = this.focusables();
        if (items.length === 0) {
            return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    focusables() {
        return [
            ...this.element.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ),
        ].filter((el) => el.offsetParent !== null || el === document.activeElement);
    }
}
