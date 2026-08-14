/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Success toasts, stacked on the right of the screen.
 *
 * Two sources feed it: server flashes rendered inside the container on page
 * load (redirect-based actions), and the `toast:show` window event dispatched
 * by LiveComponents that stay on the page. The markup lives in a <template>
 * so the styling stays in Twig.
 */
export default class extends Controller {
    static targets = ['template', 'list'];

    static values = { delay: { type: Number, default: 5000 } };

    connect() {
        this.timers = new Set();
        this.onShow = (event) => this.push(event.detail?.message ?? '');
        window.addEventListener('toast:show', this.onShow);

        // Flash-rendered toasts fade out on the same timer as the live ones.
        this.listTarget.querySelectorAll('[data-toast]').forEach((toast) => {
            this.enter(toast);
            this.scheduleDismiss(toast);
        });
    }

    disconnect() {
        window.removeEventListener('toast:show', this.onShow);
        this.timers.forEach((id) => clearTimeout(id));
        this.timers.clear();
    }

    /** Clones the template, fills the message in and stacks it. */
    push(message) {
        if ('' === message) {
            return;
        }

        const toast = this.templateTarget.content.firstElementChild.cloneNode(true);
        toast.querySelector('[data-toast-message]').textContent = message;
        this.listTarget.append(toast);
        this.enter(toast);
        this.scheduleDismiss(toast);
    }

    dismiss(event) {
        this.remove(event.currentTarget.closest('[data-toast]'));
    }

    /** Next frame, so the entering transition actually plays. */
    enter(toast) {
        requestAnimationFrame(() => toast.setAttribute('data-entered', ''));
    }

    scheduleDismiss(toast) {
        const id = setTimeout(() => {
            this.timers.delete(id);
            this.remove(toast);
        }, this.delayValue);
        this.timers.add(id);
    }

    remove(toast) {
        if (!toast) {
            return;
        }

        toast.removeAttribute('data-entered');
        // Matches the leave transition duration of the template markup.
        const id = setTimeout(() => {
            this.timers.delete(id);
            toast.remove();
        }, 200);
        this.timers.add(id);
    }
}
