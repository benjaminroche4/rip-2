/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Safety net for the header popovers: they live in the top layer (position: fixed), so if the
 * header ever leaves the viewport (sticky disabled, very short viewport) they would stay stuck on
 * screen alone. Close them as soon as the header starts leaving the viewport.
 */
export default class extends Controller {
    static targets = ['popover'];

    connect() {
        this.observer = new IntersectionObserver(([entry]) => {
            if (entry.intersectionRatio < 1 && entry.boundingClientRect.top < 0) {
                this.closePopovers();
            }
        }, { threshold: 1 });
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
    }

    closePopovers() {
        this.popoverTargets.forEach((popover) => {
            if (popover.matches(':popover-open')) {
                popover.hidePopover();
            }
        });
    }
}
