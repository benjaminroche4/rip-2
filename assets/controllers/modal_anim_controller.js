/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Entrance micro-transition for confirmation modals. The root gains
 * [data-entered] one frame after mount so the CSS transitions written with
 * group-data-entered/modal variants play in. Closing is handled by
 * instant-exit (immediate hide, no reverse transition): the real removal
 * only lands with the live re-render, and a lingering half-faded modal
 * reads as a stacking glitch.
 */
export default class extends Controller {
    connect() {
        this.frame = requestAnimationFrame(() => {
            this.frame = requestAnimationFrame(() => {
                this.element.dataset.entered = '';
            });
        });
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
    }
}
